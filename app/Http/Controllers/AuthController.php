<?php

namespace App\Http\Controllers;

use App\Mail\ResetPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\UserResource;
use Exception;

class AuthController extends Controller
{
    //Register user
    public function register(Request $request)
    {
        $data = $request->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|string|unique:users,email',
            'mobile' => 'required|string|unique:users,mobile',
            'password' => 'required|string|min:6'
        ]);

        $rawPassword = $data['password'];
        $data['password'] = Hash::make($rawPassword);
        $email = $data['email'];
        $mobile = $data['mobile'];

        $user = User::where('email',$email)->orWhere('mobile',$mobile)->first();

        if($user){
            return $this->sendError($error = 'User details already exists', $code = 403);
        }

        $savedUser = User::create($data);
        $token = $savedUser->createToken("access_token")->plainTextToken;
        $savedUser->refreshToken = $token;
        
        $cookie = Cookie::make('access_token', $savedUser->id, minutes: 72 * 60 * 60 * 1000, httpOnly: true);

       return response()->json([
            'success' => true,
            'data' => 
            [
                'id' => $savedUser->id,
                'firstname' => $savedUser->firstname,
                'lastname' => $savedUser->lastname,
                'email' => $savedUser->email,
                'mobile' => $savedUser->mobile,
                'token' => $token,
            ],
            'message' => 'User created successfully',
        ])->withCookie($cookie);
    }

    //Login User
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|string|exists:users,email',
            'password' => 'required|string'
        ]);
        
        $user = User::where('email',$data['email'])->first();

        $password = Hash::check($data['password'], $user->getAuthpassword());

        if(!$user && !$password){
            return $this->sendError($error="Invalid Credentials");
        }

        $token = $user->createToken("access_token")->plainTextToken;

        $user->update(['refreshToken' => $token]);

        $cookie = Cookie::make('access_token', $user->id, minutes: 72 * 60 * 60 * 1000, httpOnly: true);

        return response()->json([
            'success' => true,
            'data' => 
            [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'token' => $token,
            ],
            'message' => 'User logged in successfully',
        ])->withCookie($cookie);
    }

    //Login Admin
    public function loginAdmin(Request $request)
    {

        $data = $request->validate([
            'email' => 'required|email|string|exists:users,email',
            'password' => 'required|string'
        ]);
        
        $admin = User::where('email',$data['email'])->first();

        $password = Hash::check($data['password'], $admin->getAuthpassword());

        if(!$admin && !$password){
            return $this->sendError($error="Invalid Credentials");
        }

        if($admin->role !== 'admin'){
            return $this->sendError($error="Unauthorized");
        }

        $token = $admin->createToken("access_token")->plainTextToken;
        $admin->refreshToken = $token;
        $admin->save();

        $cookie = Cookie::make('access_token', $admin->id, minutes: 72 * 60 * 60 * 1000, httpOnly: true);


        return response()->json([
            'success' => true,
            'data' => 
            [
                'id' => $admin->id,
                'firstname' => $admin->firstname,
                'lastname' => $admin->lastname,
                'email' => $admin->email,
                'mobile' => $admin->mobile,
                'token' => $token,
            ],
            'message' => 'Admin logged in successfully',
        ])->withCookie($cookie);
    }

    //Refresh token
    public function refreshToken()
    {
        $accessToken = Cookie::get('access_token');
        if(!$accessToken){
            return $this->sendError($error='No Refresh token in Cookie');
        }

        $user = User::where('id', $accessToken)->first();
        if(!$user){
            return $this->sendError($error='No user id for this refresh token');
        }

        $accessToken = $user->createToken("access_token")->plainTextToken;

        return response()->json([
            'status' => true,
            'access_token' => $accessToken,
        ]);
    }

    //Logout user
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            'status' => true,
            'message' => 'Logout successful'
        ])->withCookie(Cookie::forget('access_token'));
    }


    //Update password
    public function updatePassword(Request $request,User $user)
    {
        $data = $request->validate([
            'password' => 'required',
            'new_password' => 'required|confirmed'
        ]);

        $currentPassword = Hash::check($data['password'], $user->getAuthpassword());

        if(!$currentPassword){
            return $this->sendError($error = 'Your current password is incorrect');
        }

        $user->password = Hash::make($data['new_password']);
        $user->save();

        return $this->sendResponse(UserResource::make($user)
                ->response()
                ->getData(true), 'Password changed successfully');

    }

    //Forgot password token
    public function forgotPassword(User $user)
    {
        if($user){
            $email = $user->email;
            $token = $user->createResetPasswordToken();
            $user->save();

            $domain = "http://localhost:8000/api/users";
            $resetUrl = $domain.'/reset-password?email='.$email.'&token='.$token;

            Mail::to($email)->send(new ResetPassword($user,$resetUrl));

            return response()->json([ 
                'status' => true,
                'token' => $token,
                'message' => 'Please check your mail, we have sent a password reset link valid for the next 10 minutes'
            ]);
        }
    }

    //Reset password
    public function resetPassword(Request $request)
    {
        try{
            $data = $request->validate([
                'email' => 'required|email',
                'token' => 'required',
                'password' => 'required|confirmed'
            ]);
            $user = User::where('email', $data['email'])
                    ->where('password_reset_expires', '>=', now())
                    ->first();

            if ($user && Hash::check($data['token'], $user->password_reset_token)) {
                $user->password = Hash::make($data['password']);
                $user->password_reset_token = null;
                $user->password_reset_expires = null;
                $user->password_changed_at = now();
                $user->save();

                return $this->sendResponse(UserResource::make($user)
                ->response()
                ->getData(true), "Password reset successfully" );

            } else {
                return $this->sendError($error = "Invalid token or email provided, please try again");
            }
        } catch (Exception $error) {
            return $this->sendError($error->getMessage(), $code = 500); 
        }
    }
}