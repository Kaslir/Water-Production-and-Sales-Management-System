<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditTrail;
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

    public function create()
    {
        return view('users.form', ['user' => new User]);
    }

    public function store(Request $request, AuditTrail $auditTrail)
    {
        $data = $this->validated($request);
        $user = User::create($data);
        $auditTrail->record('user.created', $user, $request->user()->user_id, [], ['role' => $user->role, 'is_active' => $user->is_active]);

        return redirect()->route('users.index')->with('status', 'User account created successfully.');
    }

    public function edit(User $user)
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user, AuditTrail $auditTrail)
    {
        $data = $this->validated($request, $user);
        abort_if($user->role === 'administrator' && $user->is_active && ($data['role'] !== 'administrator' || ! $data['is_active']) && User::where('role', 'administrator')->where('is_active', true)->count() <= 1, 422, 'At least one active administrator account must remain.');
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $before = $user->only(['name', 'email', 'role', 'is_active']);
        $user->update($data);
        $auditTrail->record('user.updated', $user, $request->user()->user_id, $before, $user->only(['name', 'email', 'role', 'is_active']));

        return redirect()->route('users.index')->with('status', 'User account updated successfully.');
    }

    public function destroy(Request $request, User $user, AuditTrail $auditTrail)
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        abort_if($user->role === 'administrator' && User::where('role', 'administrator')->where('is_active', true)->count() <= 1, 422, 'At least one active administrator account must remain.');
        $user->update(['is_active' => false]);
        $auditTrail->record('user.deactivated', $user, $request->user()->user_id, ['is_active' => true], ['is_active' => false]);

        return redirect()->route('users.index')->with('status', 'User account deactivated. Historical records remain traceable.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->user_id, 'user_id')],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['required', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'confirmed'],
        ]);
    }
}
