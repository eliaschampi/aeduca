<?php

namespace Tests\Feature;

use App\Models\AuthAccount;
use App\Models\DriveFile;
use App\Models\User;
use App\Support\Drive\DriveStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the invariants the Drive model rests on: one owner per file, folder
 * operations that carry their subtree, and sharing as read-only access.
 */
class DriveTest extends TestCase
{
    public function test_upload_stores_the_blob_and_stays_inside_the_owner_space(): void
    {
        Storage::fake('local');
        $account = $this->driveEmployee();
        $stranger = $this->driveEmployee();

        $this->actingAs($account)->get(route('drive.index'))->assertOk();

        $response = $this->actingAs($account)
            ->post(route('drive.files.store'), [
                'file' => UploadedFile::fake()->image('foto.png', 200, 200),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $file = DriveFile::query()->firstOrFail();
        $this->assertSame($account->user->code, $file->user_code);
        $this->assertSame('img', $file->type);
        Storage::disk('local')->assertExists((string) $file->storage_path);
        $this->assertSame($file->size, $response->json('storage.used'));

        $this->assertSame(
            [$file->code],
            collect($this->listFiles($account)->json('files'))->pluck('code')->all(),
        );
        $this->assertSame([], $this->listFiles($stranger)->json('files'));
        $this->actingAs($stranger)->get(route('drive.files.serve', $file))->assertNotFound();
    }

    public function test_a_folder_carries_its_subtree_into_and_out_of_the_trash(): void
    {
        $account = $this->driveEmployee();
        $parent = $this->makeFolder($account->user, 'Padre');
        $child = $this->makeFolder($account->user, 'Hijo', $parent);
        $leaf = $this->makeFile($account->user, 'nota.txt', $child);

        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $parent), ['trashed' => true])
            ->assertOk();

        $this->assertNotNull($child->fresh()->deleted_at);
        $this->assertNotNull($leaf->fresh()->deleted_at);

        // The trash lists what was deleted, not every descendant of it.
        $this->assertSame(
            [$parent->code],
            collect($this->listFiles($account, ['view' => 'trash'])->json('files'))->pluck('code')->all(),
        );

        // A child cannot outlive its trashed parent.
        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $child), ['trashed' => false])
            ->assertStatus(422);

        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $parent), ['trashed' => false])
            ->assertOk();

        $this->assertNull($child->fresh()->deleted_at);
        $this->assertNull($leaf->fresh()->deleted_at);
    }

    public function test_a_folder_is_never_a_destination_inside_its_own_subtree(): void
    {
        $account = $this->driveEmployee();
        $parent = $this->makeFolder($account->user, 'Padre');
        $child = $this->makeFolder($account->user, 'Hijo', $parent);
        $other = $this->makeFolder($account->user, 'Otro');

        $this->actingAs($account)
            ->patchJson(route('drive.files.update', $parent), ['parent_code' => $child->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_code');
        $this->assertNull($parent->fresh()->parent_code);

        $destinations = collect(
            $this->actingAs($account)
                ->getJson(route('drive.folders', ['exclude' => $parent->code]))
                ->assertOk()
                ->json('folders'),
        );
        $this->assertSame([$other->code], $destinations->pluck('code')->all());
    }

    public function test_sharing_grants_read_only_access_until_it_is_revoked(): void
    {
        Storage::fake('local');
        $account = $this->driveEmployee();
        $recipient = $this->driveEmployee();
        $file = $this->makeFile($account->user, 'informe.txt');
        Storage::disk('local')->put((string) $file->storage_path, 'contenido');

        $shareCode = $this->actingAs($account)
            ->postJson(route('drive.shares.store', $file), ['user_code' => $recipient->user->code])
            ->assertCreated()
            ->json('shares.0.code');

        $this->assertSame(
            [$file->code],
            collect($this->listFiles($account, ['view' => 'shared_by_me'])->json('files'))
                ->pluck('code')
                ->all(),
        );

        $received = collect($this->listFiles($recipient, ['view' => 'shared_with_me'])->json('files'));
        $this->assertSame([$file->code], $received->pluck('code')->all());
        $this->assertSame('shared', $received->first()['scope']);

        $this->actingAs($recipient)->get(route('drive.files.serve', $file))->assertOk();
        $this->actingAs($recipient)
            ->patchJson(route('drive.files.update', $file), ['name' => 'secuestrado.txt'])
            ->assertNotFound();

        $this->actingAs($account)
            ->deleteJson(route('drive.shares.destroy', ['file' => $file->code, 'share' => $shareCode]))
            ->assertOk()
            ->assertJsonCount(0, 'shares');

        $this->actingAs($recipient)->get(route('drive.files.serve', $file))->assertNotFound();
    }

    public function test_sharing_a_folder_grants_its_subtree_and_nothing_above_it(): void
    {
        Storage::fake('local');
        $account = $this->driveEmployee();
        $recipient = $this->driveEmployee();

        $private = $this->makeFolder($account->user, 'Privado');
        $shared = $this->makeFolder($account->user, 'Compartido', $private);
        $inside = $this->makeFile($account->user, 'informe.txt', $shared);
        Storage::disk('local')->put((string) $inside->storage_path, 'contenido');

        $this->actingAs($account)
            ->postJson(route('drive.shares.store', $shared), ['user_code' => $recipient->user->code])
            ->assertCreated();

        // The recipient sees the share root, not one row per contained file.
        $this->assertSame(
            [$shared->code],
            collect($this->listFiles($recipient, ['view' => 'shared_with_me'])->json('files'))
                ->pluck('code')
                ->all(),
        );

        $listing = $this->listFiles($recipient, ['parent' => $shared->code]);
        $this->assertSame(
            [$inside->code],
            collect($listing->json('files'))->pluck('code')->all(),
        );
        $this->assertFalse($listing->json('owned'));
        // The trail starts at the share root: the owner's folder above it is never named.
        $this->assertSame(['Compartido'], array_column($listing->json('path'), 'name'));

        $this->actingAs($recipient)->get(route('drive.files.serve', $inside))->assertOk();
        $this->actingAs($recipient)
            ->getJson(route('drive.files', ['parent' => $private->code]))
            ->assertNotFound();
    }

    private function driveEmployee(): AuthAccount
    {
        $account = $this->createEmployeeAccount();
        $this->grantPermissions($account, ['drive.manage']);

        return $account;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function listFiles(AuthAccount $account, array $query = []): TestResponse
    {
        return $this->actingAs($account)
            ->getJson(route('drive.files', $query))
            ->assertOk();
    }

    private function makeFolder(User $owner, string $name, ?DriveFile $parent = null): DriveFile
    {
        return $this->makeNode($owner, $name, 'dir', $parent, null);
    }

    private function makeFile(User $owner, string $name, ?DriveFile $parent = null): DriveFile
    {
        return $this->makeNode(
            $owner,
            $name,
            'doc',
            $parent,
            DriveStorage::DIRECTORY.'/'.$owner->code.'-'.$name,
        );
    }

    private function makeNode(
        User $owner,
        string $name,
        string $type,
        ?DriveFile $parent,
        ?string $storagePath,
    ): DriveFile {
        $node = new DriveFile([
            'parent_code' => $parent?->code,
            'name' => $name,
            'type' => $type,
            'size' => $storagePath ? 9 : 0,
            'storage_path' => $storagePath,
            'mime_type' => $storagePath ? 'text/plain' : null,
        ]);
        $node->user_code = $owner->code;
        $node->save();

        return $node;
    }
}
