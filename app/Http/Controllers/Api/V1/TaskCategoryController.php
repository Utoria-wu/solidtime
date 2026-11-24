<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Models\TaskCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TaskCategoryController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        $this->checkAnyPermission($organization, ['tasks:view', 'tasks:view:all']);

        return response()->json(['data' => TaskCategory::query()->where('organization_id', $organization->id)
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()->map($this->serialize(...))]);
    }

    public function store(Organization $organization, Request $request): JsonResponse
    {
        $this->checkPermission($organization, 'tags:create');
        $category = new TaskCategory;
        $category->organization_id = $organization->id;

        return $this->save($organization, $category, $request, 201);
    }

    public function update(Organization $organization, TaskCategory $category, Request $request): JsonResponse
    {
        $this->checkPermission($organization, 'tags:update');
        abort_unless($category->organization_id === $organization->id, 404);

        return $this->save($organization, $category, $request);
    }

    private function save(Organization $organization, TaskCategory $category, Request $request, int $status = 200): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('task_categories', 'name')->where('organization_id', $organization->id)->ignore($category->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_archived' => ['sometimes', 'boolean'],
        ]);
        $category->name = $input['name'];
        $category->color = $input['color'];
        $category->sort_order = $input['sort_order'] ?? $category->sort_order ?? 0;
        if (array_key_exists('is_archived', $input)) {
            $category->archived_at = $input['is_archived'] ? ($category->archived_at ?? Carbon::now()) : null;
        }
        $category->save();

        return response()->json(['data' => $this->serialize($category)], $status);
    }

    private function serialize(TaskCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'color' => $category->color,
            'sort_order' => $category->sort_order, 'is_archived' => $category->archived_at !== null];
    }
}
