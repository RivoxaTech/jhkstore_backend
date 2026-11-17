<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Auth\Models\Admin;
use App\Domain\Catalog\Models\Tag;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/** Admin-managed tags. System tags (best-seller, hot-selling ...) keep their slug and can never be deleted. */
class SaveTag
{
    public function create(array $data, Admin $admin): Tag
    {
        $tag = new Tag($data);
        $tag->slug = Tag::uniqueSlug($data['name']);
        $tag->sort_order = $data['sort_order'] ?? (Tag::max('sort_order') + 1);
        $tag->is_system = false;
        $tag->is_active = $data['is_active'] ?? true;
        $tag->save();
        Audit::log($admin, 'tag.created', $tag);

        return $tag;
    }

    public function update(Tag $tag, array $data, Admin $admin): Tag
    {
        if (! $tag->is_system && isset($data['name']) && $data['name'] !== $tag->name) {
            $tag->slug = Tag::uniqueSlug($data['name'], $tag->id);
        }
        $tag->fill($data)->save();
        Audit::log($admin, 'tag.updated', $tag, $tag->getChanges());

        return $tag;
    }

    public function delete(Tag $tag, Admin $admin): void
    {
        if ($tag->is_system) {
            throw ValidationException::withMessages(['tag' => 'System tags cannot be deleted. Deactivate it instead.']);
        }
        $tag->delete(); // product_tag rows go with it (cascade)
        Audit::log($admin, 'tag.deleted', $tag);
    }
}
