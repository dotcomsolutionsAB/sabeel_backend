<?php

namespace App\Http\Controllers;
use App\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class UserController extends Controller
{
    //
    use ApiResponse;
    // Register API
    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'     => 'required|string|max:255',
                'email'    => 'nullable|email|unique:users,email',
                'password' => 'required|string|min:6|confirmed',
                'username' => 'required|string|max:255|unique:users,username',
                'role'     => 'required|in:admin,user',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'username' => $request->username,
                'role'     => $request->role,
            ]);

            return $this->success('Data saved successfully', $user, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'User create failed');
        }
    }

    // fetch
    public function fetch(Request $request, $id = null)
    {
        try {
            // SINGLE
            if ($id !== null) {
                $user = User::select('id','name','email','username','role','created_at')->find($id);

                if (!$user) {
                    return $this->error('User not found.', 404);
                }

                return $this->success('Data fetched successfully', $user, 200);
            }

            // LIST (with pagination)
            $limit  = max(1, (int) $request->input('limit', 10));
            $offset = max(0, (int) $request->input('offset', 0));
            $search = trim((string) $request->input('search', ''));
            $role = trim((string) $request->input('role', ''));

            $q = User::select('id','name','email','username','role','created_at')
                ->orderByRaw("CASE WHEN role = 'admin' THEN 1 WHEN role = 'user' THEN 2 ELSE 3 END")
                ->orderBy('name', 'asc');

            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
                });
            }

            if ($role !== '') {
                $q->where('role', $role);
            }

            $total = (clone $q)->count();

            $items = $q->skip($offset)->take($limit)->get();
            $count = $items->count();

            return $this->success('Data fetched successfully', $items, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => $count,
                    'total'  => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'User fetch failed');
        }
    }

    // update password
    public function updatePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => ['required', 'string', 'exists:users,username'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $user = User::where('username', $request->username)->first();

            if (!$user) {
                return $this->error('User not found.', 404);
            }

            $user->password = Hash::make($request->password);
            $user->save();

            return $this->success('Data saved successfully', $user, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Password update failed');
        }
    }

    // update
    public function edit(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->error('User not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'name'     => 'required|string|max:255',
                'email'    => 'nullable|email|unique:users,email,' . $user->id,
                'username' => 'required|string|max:255|unique:users,username,' . $user->id,
                'role'     => 'required|in:admin,user,sub-admin',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $updateData = [
                'name'     => $request->name,
                'email'    => $request->email,
                'username' => $request->username,
                'role'     => $request->role,
            ];

            $user->update($updateData);

            return $this->success('Data saved successfully', $user, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'User update failed');
        }
    }

    // delete
    public function delete($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return $this->error('User not found.', 404);
            }

            $user->delete();

            return $this->success('User Deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'User delete failed');
        }
    }
}
