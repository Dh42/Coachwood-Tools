<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/** Admin > Activity, ported from the old app. */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        // Ties on the second (a new account and its first sign-in) fall back to the ID.
        $query = Activity::query()->with(['causer', 'subject'])->latest()->latest('id');

        if ($logName = $request->string('log_name')->toString()) {
            $query->where('log_name', $logName);
        }

        return view('admin.activity.index', [
            'activities' => $query->paginate(25)->withQueryString(),
            'logNames' => Activity::query()->select('log_name')->distinct()->pluck('log_name'),
            'selectedLogName' => $logName,
        ]);
    }
}
