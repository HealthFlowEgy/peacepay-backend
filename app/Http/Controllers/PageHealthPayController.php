<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Http\Helpers\Response;

class PageHealthPayController extends Controller
{
    public function index(Request $request) {
        return view('healthpay.index');
    }
}
