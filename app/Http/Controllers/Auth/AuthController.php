<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    //
    use ApiResponse;
    // login
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|string|exists:users,username',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
            return $this->validation($validator);
            }

            if (!Auth::attempt(['username' => $request->username, 'password' => $request->password])) {
                return $this->error('Invalid Username or Password.', 401);
            }

            $user  = Auth::user();
            $token = $user->createToken('API TOKEN')->plainTextToken; // requires Sanctum

            return $this->success('User logged in successfully!', [
                'role'     => $user->role,
                'token'    => $token,
                'user_id'  => $user->id,
                'name'     => $user->name,
                'username' => $user->username,
                'email'    => $user->email,
            ], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Login failed');
        }
    }

    // logout
    public function logout(Request $request)
    {
        try {
            $request->user()->tokens->each(fn($token) => $token->delete());

            return $this->success('Successfully logged out.', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Logout failed');
        }
    }
}
