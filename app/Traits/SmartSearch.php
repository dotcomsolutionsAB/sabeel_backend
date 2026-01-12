<?php

namespace App\Traits;

trait SmartSearch
{
    /**
     * Tokenize search string by removing spaces, dots, commas and splitting into keywords
     * 
     * @param string $search
     * @return array
     */
    protected function tokenizeSearch(string $search): array
    {
        // Remove spaces, dots, commas and split into keywords
        $cleaned = preg_replace('/[\s.,]+/', ' ', $search);
        $cleaned = trim($cleaned);
        
        if (empty($cleaned)) {
            return [];
        }
        
        // Split by spaces to get keywords
        $keywords = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY);
        
        // Filter out empty strings and trim each keyword
        $keywords = array_map('trim', $keywords);
        $keywords = array_filter($keywords, function($keyword) {
            return !empty($keyword);
        });
        
        return array_values($keywords);
    }

    /**
     * Apply smart search to a query builder
     * Each keyword must match (AND logic between keywords)
     * Within each keyword, any column can match (OR logic within columns)
     * 
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param string $search
     * @param array $columns Array of column names to search in
     * @param callable|null $callback Optional callback for complex search logic (receives $query, $keyword)
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    protected function applySmartSearch($query, string $search, array $columns, ?callable $callback = null)
    {
        $keywords = $this->tokenizeSearch($search);
        
        if (empty($keywords)) {
            return $query;
        }
        
        // Apply AND logic: all keywords must match
        return $query->where(function ($w) use ($keywords, $columns, $callback) {
            foreach ($keywords as $keyword) {
                $w->where(function ($q) use ($keyword, $columns, $callback) {
                    // Search in each column (OR logic within columns)
                    foreach ($columns as $column) {
                        $q->orWhere($column, 'like', "%{$keyword}%");
                    }
                    
                    // Execute callback if provided for additional search logic
                    if ($callback !== null) {
                        $callback($q, $keyword);
                    }
                });
            }
        });
    }
}
