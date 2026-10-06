<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the signed-in user's display name.
     */
    public function update(ProfileUpdateRequest $request, UserService $users): RedirectResponse
    {
        $users->updateOwnName($request->user(), $request->validated('name'));

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
