<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreInquiryRequest;
use App\Models\Inquiry;
use App\Models\InquiryType;
use Illuminate\Http\JsonResponse;

class InquiryController extends Controller
{
    public function store(StoreInquiryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $inquiryTypeId = InquiryType::query()
            ->where('slug', 'client')
            ->value('id');

        $message = $data['message'];

        if (! empty($data['service'])) {
            $message = "Service: {$data['service']}\n".$message;
        }

        if (! empty($data['budget'])) {
            $message = "Budget: {$data['budget']}\n".$message;
        }

        $inquiry = Inquiry::create([
            'fullname' => $data['fullname'],
            'address' => $data['address'] ?? null,
            'contact' => $data['contact'],
            'inquiry_type_id' => $inquiryTypeId,
            'contact_channel' => $data['contact_channel'],
            'message' => $message,
            'status' => 'new',
            'created_by' => null,
        ]);

        return response()->json([
            'message' => 'Thank you, your inquiry has been received.',
            'id' => $inquiry->id,
        ], 201);
    }
}
