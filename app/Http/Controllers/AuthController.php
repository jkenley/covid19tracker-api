<?php

namespace App\Http\Controllers;

use App\Utilities\ProxyRequest;
use Illuminate\Http\Request;

use App\User;

class AuthController extends Controller
{
    protected $proxy;

    public function __construct(ProxyRequest $proxy)
    {
        $this->proxy = $proxy;
    }

    public function register()
    {
        $this->validate(request(), [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::create([
            'name' => request('name'),
            'email' => request('email'),
            'password' => bcrypt(request('password')),
        ]);

        $resp = $this->proxy->grantPasswordToken(
            $user->email,
            request('password')
        );

        return response([
            'token' => $resp->access_token,
            'expiresIn' => $resp->expires_in,
            'message' => 'Your account has been created',
        ], 201);
    }

    public function login()
    {
        $user = User::where('email', request('email'))->with(['roles', 'provinces'])->first();

        abort_unless($user, 404, 'This combination does not exists.');
        abort_unless(
            \Hash::check(request('password'), $user->password),
            403,
            'This combination does not exists.'
        );

        $resp = $this->proxy
            ->grantPasswordToken(request('email'), request('password'));

        // single-role mode
        $user->role = $user->roles->pluck('name')[0];

        return response([
            'token' => $resp->access_token,
            'refresh_token' => $resp->refresh_token,
            'expiresIn' => $resp->expires_in,
            'message' => 'You have been logged in',
            'user' => $user,
        ], 200);
    }

    public function user()
    {
        $user = auth()->guard('api')->user();
        $user->load(['roles', 'provinces']);
        // single-role mode
        $user->role = $user->roles->pluck('name')[0];
        return response([
            'user' => $user,
        ], 200);
    }

    public function refreshToken()
    {
        $resp = $this->proxy->refreshAccessToken();
        
        if( isset($resp->error) ) {
            abort( 403, $resp->error_description );
        }

        return response([
            'token' => $resp->access_token,
            'refresh_token' => $resp->refresh_token,
            'expiresIn' => $resp->expires_in,
            'message' => 'Token has been refreshed.',
        ], 200);
    }

    public function logout()
    {
        $token = request()->user()->token();
        $token->delete();

        // remove the httponly cookie
        cookie()->queue(cookie()->forget('refresh_token'));

        return response([
            'message' => 'You have been successfully logged out',
        ], 200);
    }

}