<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * Display a listing of users for administrators.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user || $user->role !== 'administrator') {
            abort(403, 'This action is unauthorized.');
        }

        return UserResource::collection(User::query()->orderBy('id')->get());
    }
}
