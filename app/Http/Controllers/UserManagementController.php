<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create() { return view('users.form', ['user' => new User]); }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        User::create($data);
        return redirect()->route('users.index')->with('status', 'User account created successfully.');
    }

    public function edit(User $user) { return view('users.form', compact('user')); }

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);
        if (blank($data['password'] ?? null)) unset($data['password']);
        $user->update($data);
        return redirect()->route('users.index')->with('status', 'User account updated successfully.');
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        abort_if($user->role === 'administrator' && User::where('role', 'administrator')->count() <= 1, 422, 'At least one administrator account must remain.');
        $user->delete();
        return redirect()->route('users.index')->with('status', 'User account deleted.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->user_id, 'user_id')],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
        ]);
    }
}
