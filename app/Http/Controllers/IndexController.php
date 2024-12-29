<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class IndexController extends Controller
{
    private $client;

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->build();
    }

    /**
     * بازسازی ایندکس با تنظیمات edge n-gram
     */
    public function setupIndex()
    {
        ini_set('max_execution_time', 300); // تنظیم زمان اجرا به ۵ دقیقه

        try {
            $this->client->indices()->delete(['index' => 'document1']); // حذف ایندکس قبلی
        } catch (Exception $e) {
            // نادیده گرفتن خطا اگر ایندکس وجود ندارد
        }

        $params = [
            'index' => 'document1',
            'body' => [
                'settings' => [
                    'analysis' => [
                        'tokenizer' => [
                            'edge_ngram_tokenizer' => [
                                'type' => 'edge_ngram',
                                'min_gram' => 2, // طول حداقل توکن‌ها
                                'max_gram' => 8, // طول حداکثر توکن‌ها
                                'token_chars' => ['letter', 'digit'], // کاراکترهای مجاز برای توکن‌ها
                            ],
                        ],
                        'analyzer' => [
                            'persian_analyzer' => [
                                'tokenizer' => 'edge_ngram_tokenizer',
                                'filter' => ['lowercase'], // اضافه کردن فیلتر نرمال‌سازی
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => [
                        'title' => [
                            'type' => 'text',
                            'analyzer' => 'persian_analyzer',
                            'search_analyzer' => 'persian_analyzer',
                        ],
                        'body' => [
                            'type' => 'text',
                            'analyzer' => 'persian_analyzer',
                            'search_analyzer' => 'persian_analyzer',
                        ],
                        'url' => ['type' => 'keyword'],
                        'suggest' => [
                            'type' => 'completion',
                            'analyzer' => 'persian_analyzer',
                            'preserve_separators' => true,
                            'preserve_position_increments' => true,
                            'max_input_length' => 50

                        ],
                    ],
                ],
            ],
        ];


        $this->client->indices()->create($params);

        return response()->json(['message' => 'Index created successfully!']);
    }

    /**
     * ایندکس کردن اسناد HTML در Elasticsearch
     */
    public function indexDocuments()
    {
        $this->setupIndex();
        $files = Storage::files('html');
        $errors = [];

        foreach ($files as $key => $file) {
            try {
                if (Storage::exists($file)) {
                    $content = Storage::get($file);
                    $parsed = app(HelperController::class)->parseHtml($content);

                    $url = app(HelperController::class)->extractUrl($content);

                    $title = $parsed['title'] ?? 'بدون عنوان';

                    if (str_contains($title, 'دانشگاه علم و صنعت ایران')) {
                        Log::info("Skipped indexing for file: $file due to filtered title: $title");
                        continue;
                    }

                    // محاسبه طول عنوان
                    $maxLength = 100; // طول حداکثری فرضی برای تنظیم وزن معکوس
                    $titleLength = strlen($title);

                    $weight = $maxLength - $titleLength;
                    $weight = $weight > 0 ? $weight : 1; // اگر وزن منفی شد، حداقل وزن را ۱ قرار بده

                    $params = [
                        'index' => 'document1',
                        'id' => $key,
                        'body' => [
                            'title' => $title ?? 'بدون عنوان',
                            'body' => $parsed['body'] ?? '',
                            'url' => $url,
                            'suggest' => [
                                'input' => [
                                    $parsed['title'] ?? 'بدون عنوان',
                                    $parsed['body'] ?? '',
                                ],
                                'weight' => $weight
                            ],
                        ],
                    ];




                    $this->client->index($params);
                }
            } catch (Exception $e) {
                Log::error('Error indexing document', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $file;
                continue;
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Documents indexed with some errors.',
                'errors' => $errors,
            ], 207);
        }

        return response()->json(['message' => 'Documents indexed successfully!']);
    }
}
