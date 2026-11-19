<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\RedirectResponse;

class NoticeController extends Controller
{
    public function dismiss(Notice $notice): RedirectResponse
    {
        $notice->update(['dismissed_at' => now()]);

        return back();
    }
}
