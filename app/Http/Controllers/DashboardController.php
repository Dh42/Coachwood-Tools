<?php

namespace App\Http\Controllers;

use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The home launcher: the tools the user can open, grouped. Ported from the old app, grouped for the design. */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        return view('dashboard', ['groups' => Tool::grouped(Tool::visibleTo($request->user()))]);
    }
}
