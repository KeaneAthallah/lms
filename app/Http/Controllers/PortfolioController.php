<?php

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioResource;
use App\Services\PortfolioService;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function show(Request $request)
    {
        $portfolio = (new PortfolioService)->portfolioFor($request->user());

        return PortfolioResource::make($portfolio);
    }
}
