<?php

namespace App\Http\Controllers;

use App\Models\Login;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'nullable|email|unique:users',
            'username' => 'required|unique:logins',
            'password' => 'required',
            'interest' => 'nullable',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();
        $user = User::create($input);

        if ($user) {
            $user->login()->create([
                'username' => $input['username'],
                'password' => bcrypt($input['password']),
                'user_id' => $user->id
            ]);
            return $user;
        }
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

        $userLogin = Login::where('username', $input['username'])->first();

        if ($userLogin && Hash::check($input['password'], $userLogin->password)) {
            return response()->json(['user' => $userLogin]);
        } else {
            return response()->json(['message' => 'Invalid username or password'], 401);
        }
    }
}
