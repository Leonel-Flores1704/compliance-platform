<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplianceRequirementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isGeneralEmployee = $user && $user->role === 'general_employee';

        $responsibleUserPayload = null;
        if ($this->relationLoaded('responsibleUser') && $this->responsibleUser) {
            $responsibleUserPayload = $isGeneralEmployee
                ? [
                    'id' => $this->responsibleUser->id,
                    'name' => $this->responsibleUser->name,
                ]
                : [
                    'id' => $this->responsibleUser->id,
                    'name' => $this->responsibleUser->name,
                    'email' => $this->responsibleUser->email,
                    'phone' => UserResource::maskPhone($this->responsibleUser->phone),
                ];
        }

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'description' => $this->description,
            'regulation' => $this->regulation,
            'deadline' => $this->deadline?->toDateString(),
            'status' => $this->status,
            'applies' => $this->applies,
            'responsible_user_id' => $this->responsible_user_id,
            'responsible_user' => $responsibleUserPayload,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
