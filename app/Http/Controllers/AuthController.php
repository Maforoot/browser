<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users',
            'username' => 'required|unique:users',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();
        $input['password'] = Hash::make($input['password']);
        $user = User::create($input);
        $user = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
        ];
        return response()->json([
            'user' => $user,
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();

        $userLogin = User::where('username', $input['username'])->first();

        if ($userLogin && Hash::check($input['password'], $userLogin->password)) {
            $user = [
                'id' => $userLogin->id,
                'username' => $userLogin->username,
                'email' => $userLogin->email,
            ];
            return response()->json(['user' => $user]);
        } else {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }
    }
}
