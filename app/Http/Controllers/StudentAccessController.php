<?php

namespace App\Http\Controllers;

use App\Actions\ManageStudentAccess;
use App\Http\Requests\StudentAccessRequest;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class StudentAccessController extends Controller
{
    public function update(
        StudentAccessRequest $request,
        Student $student,
        ManageStudentAccess $manageAccess,
    ): JsonResponse {
        $operation = $request->string('operation')->toString();
        $credential = $manageAccess->handle($student, $operation);

        return response()
            ->json([
                'message' => $operation === 'disable'
                    ? 'Acceso deshabilitado'
                    : ($operation === 'reset' ? 'Acceso restablecido' : 'Acceso habilitado'),
                'credential' => $credential,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
