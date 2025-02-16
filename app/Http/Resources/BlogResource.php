<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title'=> $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'is_liked' => (bool)$this->is_liked,
            'is_disliked' => (bool)$this->is_disliked,
            'likes' => $this->liked_by_count,
            'dislikes' => $this->disliked_by_count,
            'images' => MediaResource::collection($this->whenLoaded('media')),
            'author' => $this->author,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
