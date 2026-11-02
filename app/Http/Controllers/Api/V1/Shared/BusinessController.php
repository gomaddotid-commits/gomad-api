<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Identity\Application\Actions\CreateBusiness;
use App\Core\Identity\Application\Actions\ListBusinesses;
use App\Core\Identity\Application\Actions\ShowBusiness;
use App\Core\Identity\Application\Actions\UpdateBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BusinessController extends Controller
{
    public function index(Request $request, ListBusinesses $listBusinesses): JsonResponse
    {
        return response()->json([
            'data' => BusinessResource::collection($listBusinesses->execute($request->user())),
        ]);
    }

    public function store(Request $request, CreateBusiness $createBusiness): JsonResponse
    {
        $type = $request->user()->accountType()->businessType();

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => ['Akun '.$request->user()->accountType()->label().' tidak dapat membuat usaha. Daftar akun mitra lebih dulu.'],
            ]);
        }

        /*
        | Tipe divalidasi terhadap daftar statis, bukan terhadap `$type` yang baru
        | dihitung: nilai hasil pemanggilan method tidak terbaca generator kontrak,
        | sehingga `type` tidak punya enum di OpenAPI. Kecocokan dengan jenis akun
        | tetap diputuskan CreateBusiness, dan galatnya diterjemahkan menjadi 422
        | di bawah supaya klien tidak menerima 500.
        */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'type' => ['required', Rule::in(['warung', 'grosir', 'agency'])],
        ]);

        try {
            $business = $createBusiness->execute($request->user(), $data['name'], $data['type']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['type' => [$exception->getMessage()]]);
        }

        $business->setRelation('pivot', $business->members()->whereKey($request->user()->id)->firstOrFail()->pivot);

        return response()->json(['data' => ['business' => new BusinessResource($business)]], 201);
    }

    public function show(Business $business, Request $request, ShowBusiness $showBusiness): JsonResponse
    {
        return response()->json([
            'data' => ['business' => new BusinessResource($showBusiness->execute($request->user(), $business))],
        ]);
    }

    public function update(Business $business, Request $request, UpdateBusiness $updateBusiness): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
        ]);
        $business = $updateBusiness->execute($request->user(), $business, $data['name']);

        return response()->json(['data' => ['business' => new BusinessResource($business)]]);
    }
}
