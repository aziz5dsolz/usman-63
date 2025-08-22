<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    
    public function redirectToGitHub()
    {
        // Clear any existing session data to prevent state issues
        session()->forget(['github_state', 'url.intended']);

        return Socialite::driver('github')
            ->scopes(['user:email'])  // Removed 'repo' scope - only request what you need
            ->redirect();
    }

    
    public function handleGitHubCallback(Request $request)
    {
        try {
            
            // Check if user cancelled the authorization
            if ($request->has('error')) {
                
                Log::info('GitHub OAuth cancelled by user: ' . $request->get('error'));
                return redirect('/login')->withErrors(['error' => 'GitHub authorization was cancelled.']);
            }
  
            // For debugging - remove after fixing
            Log::info('GitHub callback received', [
                'code' => $request->get('code'),
                'state' => $request->get('state'),
                'session_state' => session()->get('state')
            ]);

            // Use stateless() to bypass state verification if needed temporarily
            $githubUser = Socialite::driver('github')->stateless()->user();
        

            // Alternative: Use with state verification (comment above and uncomment below after fixing session issues)
            // $githubUser = Socialite::driver('github')->user();

            // Check if GitHub user data is valid
            
            if (!$githubUser || !$githubUser->id) {
               
                Log::error('GitHub OAuth: Invalid user data received');
                return redirect('/login')->withErrors(['error' => 'Invalid GitHub user data']);
            }

            // Ensure required fields are not null
            $githubId = $githubUser->id;
            $githubEmail = $githubUser->email;
            $githubName = $githubUser->name ?? $githubUser->nickname ?? 'GitHub User';
            $githubUsername = $githubUser->nickname ?? '';
            $githubToken = $githubUser->token ?? '';
            $githubAvatar = $githubUser->avatar ?? '';

            // Debug GitHub user data
            Log::info('GitHub user data', [
                'id' => $githubId,
                'email' => $githubEmail,
                'name' => $githubName,
                'username' => $githubUsername
            ]);

            
            // Check if email is available (some GitHub accounts might not have public email)
            if (!$githubEmail) {
                
            
                return redirect('/login')->withErrors(['error' => 'GitHub account must have a public email address. Please make your email public in GitHub settings.']);
            }

            
            // Find or create user
            $user = User::where('github_id', $githubId)
                ->orWhere('email', $githubEmail)
                ->first();

            if ($user) {
                
                // Update existing user with GitHub info
                $user->update([
                    'github_id' => $githubId,
                    'github_username' => $githubUsername,
                    'github_token' => $githubToken,
                    'avatar' => $githubAvatar
                ]);

                
                Log::info('Updated existing user', ['user_id' => $user->id]);
            } else {
              
            
                // Create new user
                $user = User::create([
                    'name' => $githubName,
                    'first_name' => explode(' ', $githubName)[0] ?? $githubName,
                    'last_name' => explode(' ', $githubName, 2)[1] ?? '',
                    'email' => $githubEmail,
                    'github_id' => $githubId,
                    'github_username' => $githubUsername,
                    'github_token' => $githubToken,
                    'avatar' => $githubAvatar,
                    'password' => Hash::make(Str::random(16)),
                    'email_verified_at' => now(),
                    'role' => '2', // Default user role
                    'status' => '1' // Active status
                ]);

                Log::info('Created new user', ['user_id' => $user->id]);
            }
          
            Auth::login($user);
  
           
            // Role-based redirection
            if ($user->role == '0' || $user->role == '1') {
               
            
                return redirect()->intended(route('admin.dashboard'));
            } else {
                  
                return redirect()->intended(route('user.dashboard'));
            }

            
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('GitHub OAuth Invalid State Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_state' => $request->get('state'),
                'session_state' => session()->get('state')
            ]);

            // dd($e->getMessage());
            // Clear session and redirect with helpful message
            session()->flush();
            return redirect('/login')->withErrors(['error' => 'OAuth session expired. Please try signing in again.']);
        } catch (\Exception $e) {
            Log::error('GitHub OAuth General Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
//   dd($e->getMessage());

            return redirect('/login')->withErrors(['error' => 'GitHub authentication failed. Please try again.']);
        }
    }


    
    public function linkGitHubAccount()
    {
        if (!Auth::check()) {
            return redirect('/login')->withErrors(['error' => 'Please login first']);
        }

        return Socialite::driver('github')
            ->scopes(['user:email', 'repo'])
            ->redirect();
    }

    
    public function handleGitHubLinking()
    {
        try {
            if (!Auth::check()) {
                return redirect('/login')->withErrors(['error' => 'Please login first']);
            }

            $githubUser = Socialite::driver('github')->user();

            if (!$githubUser || !$githubUser->id) {
                return redirect()->back()->withErrors(['error' => 'Invalid GitHub user data']);
            }

            $user = Auth::user();

            // Check if GitHub account is already linked to another user
            $existingUser = User::where('github_id', $githubUser->id)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingUser) {
                return redirect()->back()->withErrors(['error' => 'This GitHub account is already linked to another user']);
            }

            $user->update([
                'github_id' => $githubUser->id,
                'github_username' => $githubUser->nickname ?? '',
                'github_token' => $githubUser->token ?? '',
                'avatar' => $githubUser->avatar ?? $user->avatar
            ]);

            return redirect()->back()->with('success', 'GitHub account linked successfully!');
        } catch (\Exception $e) {
            Log::error('GitHub Linking Error: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Failed to link GitHub account: ' . $e->getMessage()]);
        }
    }
}
