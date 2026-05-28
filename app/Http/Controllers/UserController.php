<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with('rol')->get());
    }

    public function show(User $user)
    {
        return response()->json($user->load('rol'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:usuarios',
            'password' => 'required|string|min:6',
            'rol_id' => 'required|exists:roles,id',
            'estado' => 'required|in:activo,inactivo',
        ]);

        $user = User::create([
            'nombre' => $validated['nombre'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'rol_id' => $validated['rol_id'],
            'estado' => $validated['estado'],
        ]);

        return response()->json($user->load('rol'), 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'nombre' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('usuarios')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'rol_id' => 'sometimes|required|exists:roles,id',
            'estado' => 'sometimes|required|in:activo,inactivo',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json($user->load('rol'));
    }

    public function destroy(User $user)
    {
        // Deactivation logic (Soft Delete behavior asked by user)
        $user->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Usuario desactivado correctamente', 'user' => $user->load('rol')]);
    }

    public function toggleStatus(User $user)
    {
        $newState = $user->estado === 'activo' ? 'inactivo' : 'activo';
        $user->update(['estado' => $newState]);

        return response()->json($user->load('rol'));
    }
}
