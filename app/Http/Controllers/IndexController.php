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
                                'max_gram' => 10, // طول حداکثر توکن‌ها
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

                    $params = [
                        'index' => 'document1',
                        'id' => $key,
                        'body' => [
                            'title' => $parsed['title'] ?? 'بدون عنوان',
                            'body' => $parsed['body'] ?? '',
                            'file_path' => $file,
                            'url' => $url,
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
