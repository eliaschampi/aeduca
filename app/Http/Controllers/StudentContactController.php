<?php

namespace App\Http\Controllers;

use App\Actions\CreateStudentContact;
use App\Http\Requests\StudentContactRequest;
use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StudentContactController extends Controller
{
    public function store(
        StudentContactRequest $request,
        Student $student,
        CreateStudentContact $createStudentContact,
    ): RedirectResponse {
        $createStudentContact->handle($student, $this->attributes($request));

        Inertia::flash('success', 'Contacto agregado');

        return to_route('students.show', $student);
    }

    public function update(
        StudentContactRequest $request,
        Student $student,
        StudentContact $contact,
    ): RedirectResponse {
        $contact->update($this->attributes($request));

        Inertia::flash('success', 'Contacto actualizado');

        return to_route('students.show', $student);
    }

    public function destroy(Student $student, StudentContact $contact): RedirectResponse
    {
        $contact->delete();

        Inertia::flash('success', 'Contacto eliminado');

        return to_route('students.show', $student);
    }

    /**
     * @return array{name: string, phone: ?string, note: ?string}
     */
    private function attributes(StudentContactRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'phone' => $request->input('phone'),
            'note' => $request->input('note'),
        ];
    }
}
