<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Button, Card, Chip, PageHeader, Title } from '@lumi-ui/svelte';

    const auth = $derived(page.props.auth);
    let switchingBranchCode = $state<string | null>(null);

    function selectBranch(branchCode: string): void {
        switchingBranchCode = branchCode;
        router.put(
            '/current-branch',
            { branch_code: branchCode },
            {
                preserveScroll: true,
                onFinish: () => {
                    switchingBranchCode = null;
                },
            },
        );
    }
</script>

<svelte:head>
    <title>Sedes · Aeduca</title>
</svelte:head>

<div class="lumi-stack lumi-stack--lg lumi-min-width--0">
    <PageHeader
        title="Sedes"
        subtitle="Elige la sede con la que trabajarás durante esta sesión."
        icon="building2"
        size="xl"
    />

    {#if auth}
        <div
            class="lumi-grid lumi-grid--cards lumi-grid--gap-md"
            role="list"
            aria-label="Sedes de trabajo disponibles"
        >
            {#each auth.branches as branch (branch.code)}
                {@const isCurrent = auth.current_branch?.code === branch.code}
                <div role="listitem">
                    <Card spaced selected={isCurrent} class="lumi-width--full lumi-h--full">
                        <div class="lumi-stack lumi-stack--md">
                            <div class="lumi-flex lumi-justify--between lumi-align-items--start lumi-flex--gap-md">
                                <Title size="sm" icon="building2" title={branch.name} />
                                {#if isCurrent}
                                    <Chip color="success" size="sm" icon="checkCircle">
                                        Sede activa
                                    </Chip>
                                {/if}
                            </div>

                            {#if !isCurrent}
                                <div class="lumi-flex lumi-justify--end">
                                    <Button
                                        type="button"
                                        variant="border"
                                        size="sm"
                                        icon="mapPin"
                                        loading={switchingBranchCode === branch.code}
                                        disabled={switchingBranchCode !== null}
                                        onclick={() => selectBranch(branch.code)}
                                    >
                                        Usar sede
                                    </Button>
                                </div>
                            {/if}
                        </div>
                    </Card>
                </div>
            {/each}
        </div>
    {/if}
</div>
