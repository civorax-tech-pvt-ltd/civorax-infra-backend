<?php

namespace App\Filament\Resources\BlogPostResource\Pages;

use App\Filament\Resources\BlogPostResource;
use App\Models\BlogPost;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBlogPosts extends ListRecords
{
    protected static string $resource = BlogPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Write a post'),
        ];
    }

    public function getTabs(): array
    {
        $pending = BlogPostResource::getEloquentQuery()->where('status', 'pending')->count();

        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Waiting for review')
                ->badge($pending ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'drafts' => Tab::make('Drafts')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['draft', 'changes_requested'])),
            'published' => Tab::make('Published')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return BlogPostResource::canPublish() && BlogPost::query()->where('status', 'pending')->exists() ? 'pending' : 'all';
    }
}
