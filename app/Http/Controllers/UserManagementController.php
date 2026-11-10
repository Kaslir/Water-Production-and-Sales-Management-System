<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditTrail;
use App\Services\EmployeeInvitations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['all', 'active', 'pending', 'deactivated'])],
        ]);
        $search = trim($filters['search'] ?? '');
        $role = $filters['role'] ?? '';
        $status = $filters['status'] ?? 'active';
        $users = User::query()
            ->with('latestInvitation')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($role !== '', fn ($query) => $query->where('role', $role))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'pending', fn ($query) => $query->where('is_active', false)->whereNull('activated_at')->whereHas('employeeInvitations'))
            ->when($status === 'deactivated', fn ($query) => $query->where('is_active', false)->where(fn ($q) => $q->whereNotNull('activated_at')->orWhereDoesntHave('employeeInvitations')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users', 'search', 'role', 'status'));
    }

    public function create()
    {
        return view('users.form', ['user' => new User]);
    }

    public function store(Request $request, AuditTrail $auditTrail, EmployeeInvitations $invitations)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone_number' => ['required', 'regex:/^0[17]\d{8}$/'],
            'role' => ['required', Rule::in(User::EMPLOYEE_ROLES)],
        ]);
        $data['email'] = Str::lower(trim($data['email']));
        $invitations->assertConfigured();
        $user = User::create([...$data, 'password' => Str::random(64), 'is_active' => false]);
        $auditTrail->record('employee.created_pending', $user, $request->user()->user_id, [], ['role' => $user->role]);
        $sent = $invitations->send($user, $request->user(), $auditTrail);

        return redirect()->route('users.index', ['status' => 'pending'])->with($sent ? 'status' : 'mail_error', $sent ? 'Employee created and invitation sent.' : 'Employee created, but email delivery failed. Check mail settings and resend.');
    }

    public function edit(User $user)
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user, AuditTrail $auditTrail, EmployeeInvitations $invitations)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $pending = $user->employeeInvitations()->exists() && ! $user->is_active && ! $user->activated_at;
        $data = $this->validated($request, $user, $pending);
        if ($pending) {
            $data['is_active'] = false;
        }
        abort_if($user->is($request->user()) && ! $data['is_active'], 422, 'You cannot deactivate your own account.');
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $before = $user->only(['name', 'email', 'role', 'is_active']);
        $emailChanged = $user->email !== $data['email'];
        if ($pending && $emailChanged) {
            $invitations->assertConfigured();
        }
        $accessChanged = $user->role !== $data['role'] || (bool) $user->is_active !== (bool) $data['is_active'];
        $passwordChanged = array_key_exists('password', $data);
        DB::transaction(function () use (&$user, $data, $emailChanged, $before, $auditTrail, $request): void {
            // Lock every administrator in a stable order so simultaneous role changes cannot remove the last one.
            User::query()->where('role', 'administrator')->orderBy('user_id')->lockForUpdate()->get();
            $user = User::query()->lockForUpdate()->findOrFail($user->user_id);
            abort_if($user->role === 'administrator' && $user->is_active && ($data['role'] !== 'administrator' || ! $data['is_active']) && User::where('role', 'administrator')->where('is_active', true)->count() <= 1, 422, 'At least one active administrator account must remain.');
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->update($data);
            $auditTrail->record('user.updated', $user, $request->user()->user_id, $before, $user->only(['name', 'email', 'role', 'is_active']));
        });
        if ($pending && $emailChanged) {
            $sent = $invitations->send($user, $request->user(), $auditTrail);
        }
        if ($emailChanged || $accessChanged || $passwordChanged) {
            DB::table('sessions')->where('user_id', $user->user_id)->delete();
        }

        return redirect()->route('users.index')->with($pending && $emailChanged && ! $sent ? 'mail_error' : 'status', $pending && $emailChanged ? ($sent ? 'Employee updated and a new invitation sent.' : 'Employee updated, but email delivery failed. Resend the invitation.') : 'Employee account updated successfully.');
    }

    public function destroy(Request $request, User $user, AuditTrail $auditTrail)
    {
        abort_if(! $user->is_active && ! $user->activated_at && $user->employeeInvitations()->exists(), 422, 'Revoke the pending invitation instead.');
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        DB::transaction(function () use (&$user, $auditTrail, $request): void {
            User::query()->where('role', 'administrator')->orderBy('user_id')->lockForUpdate()->get();
            $user = User::query()->lockForUpdate()->findOrFail($user->user_id);
            if (! $user->is_active) {
                return;
            }
            abort_if($user->role === 'administrator' && $user->is_active && User::where('role', 'administrator')->where('is_active', true)->count() <= 1, 422, 'At least one active administrator account must remain.');
            $user->update(['is_active' => false]);
            $auditTrail->record('user.deactivated', $user, $request->user()->user_id, ['is_active' => true], ['is_active' => false]);
        });

        return redirect()->route('users.index')->with('status', 'User account deactivated. Historical records remain traceable.');
    }

    private function validated(Request $request, ?User $user = null, bool $pending = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->user_id, 'user_id')],
            'phone_number' => ['nullable', 'regex:/^0[17]\d{8}$/'],
            'role' => ['required', Rule::in($pending ? User::EMPLOYEE_ROLES : User::ROLES)],
            'is_active' => [$pending ? 'prohibited' : 'required', 'boolean'],
            'password' => [$pending ? 'prohibited' : 'nullable', 'string', 'min:12', 'confirmed'],
        ]);
    }
}
