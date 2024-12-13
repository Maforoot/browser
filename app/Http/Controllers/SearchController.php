<?php

namespace App\Http\Controllers;

use Elastic\Elasticsearch\ClientBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SearchController extends Controller
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
                                'min_gram' => 3, // طول حداقل توکن‌ها
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
                        'suggest' => [
                            'type' => 'completion', // فیلد ساجسشن
                            'analyzer' => 'persian_analyzer',
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
                    $parsed = $this->parseHtml($content);

                    $url = $this->extractUrl($content);

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

    /**
     * جستجوی پیشوندی در Elasticsearch
     */
    public function search(Request $request)
    {
        $query = $request->input('query');
        $size = $request->input('size', 5); // تعداد نتایج در هر صفحه (پیش‌فرض 10)
        $page = $request->input('page', 1); // شماره صفحه (پیش‌فرض 1)

        // محاسبه مقدار 'from' که می‌گوید از کدام نتیجه شروع کنیم
        $from = ($page - 1) * $size;

        $params = [
            'index' => 'document1',
            'body' => [
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'match_phrase' => [
                                    'body' => [
                                        'query' => $query,
                                        'boost' => 150,
                                    ],
                                ],
                            ],
                            [
                                'match' => [
                                    'body' => [
                                        'query' => $query,
                                        'fuzziness' => 2,
                                        'boost' => 15,
                                    ],
                                ],
                            ],
                            [
                                'match' => [
                                    'title' => [
                                        'query' => $query,
                                        'operator' => 'AND',
                                        'fuzziness' => 2,
                                        'boost' => 30,
                                    ],
                                ],
                            ],
                            [
                                'match_phrase_prefix' => [
                                    'title' => [
                                        'query' => $query,
                                        'boost' => 60,
                                    ],
                                ],
                            ],
                            [
                                'prefix' => [
                                    'body' => [
                                        'value' => $query,
                                        'boost' => 10,
                                    ],
                                ],
                            ],
                        ],
                        'minimum_should_match' => 1, // حداقل یک شرط باید برقرار باشد
                    ],
                ],
                'sort' => [
                    '_score' => ['order' => 'desc'],
                ],
                'highlight' => [
                    'fields' => [
                        'body' => [
                            'type' => 'unified',
                            'pre_tags' => ['<strong>'], // استفاده از <strong> برای هایلایت کردن
                            'post_tags' => ['</strong>'],
                            'fragment_size' => 175,
                            'number_of_fragments' => 5,
                            'require_field_match' => true, // فقط تطابق دقیق
                            'highlight_query' => [
                                'match_phrase' => [
                                    'body' => [
                                        'query' => $query,
                                        'boost' => 150,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'size' => $size, // تعداد نتایج در هر صفحه
                'from' => $from, // از کدام نتیجه شروع کنیم
            ],
        ];


        $response = $this->client->search($params);
        if (empty($response['hits']['hits'][0]['highlight']['body'][0])) {
            // اگر هیچ نتیجه‌ای برای match_phrase پیدا نشد، از match برای هایلایت استفاده می‌کنیم
            $params['body']['highlight']['fields']['body']['highlight_query'] = [
                'match' => [
                    'body' => [
                        'query' => $query,
                        'fuzziness' => 2,
                        'boost' => 15,
                    ],
                ],
            ];
            $response = $this->client->search($params);

        }
        // ساخت نتایج جستجو برای ارسال به کاربر
        $results = collect($response['hits']['hits'])->map(function ($hit) {
            $highlightedBody = $hit['highlight']['body'][0] ?? 'متنی برای نمایش وجود ندارد';
            return [
                'id' => $hit['_id'],
                'url' => $hit['_source']['url'] ?? null,
                'title' => $hit['_source']['title'],
                'body' => $highlightedBody . '...',
            ];
        });

        // ارسال نتایج به همراه اطلاعات صفحه‌بندی
        return response()->json([
            'results' => $results,
            'total' => $response['hits']['total']['value'], // تعداد کل نتایج
            'page' => (int)$page,
            'size' => $size,
        ]);
    }


    /**
     * پردازش محتوای HTML برای استخراج عنوان و متن اصلی
     */
    private function parseHtml($content)
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        $title = $doc->getElementsByTagName('title')->item(0)->textContent ?? null;

        // استفاده از Xpath برای حذف استایل‌ها، اسکریپت‌ها و دیگر تگ‌های اضافی
        $xpath = new \DOMXPath($doc);
        $body = $xpath->query('//body')->item(0);

        // حذف تگ‌های style و script از body
        foreach ($body->getElementsByTagName('style') as $style) {
            $style->parentNode->removeChild($style);
        }

        foreach ($body->getElementsByTagName('script') as $script) {
            $script->parentNode->removeChild($script);
        }

        // دریافت تنها متن از body
        $bodyText = $body->textContent ?? null;

        // نرمال‌سازی متن
        $title = $this->normalizeText($title);
        $bodyText = $this->normalizeText($bodyText);

        return [
            'title' => $title,
            'body' => $bodyText,
        ];
    }

    /**
     * تابع کمکی برای نرمال‌سازی متن
     */
    private function normalizeText($text)
    {
        $mapping = [
            'ي' => 'ی',
            'ك' => 'ک',
            'أ' => 'ا',
            'إ' => 'ا',
            'ؤ' => 'و',
            'ئ' => 'ی',
            'ۀ' => 'ه',
            'هٔ' => 'ه',
            'ة' => 'ه',
            '‌' => ' ', // نیم‌فاصله به فاصله کامل
            'ـ' => '',  // حذف خط تزیینی
        ];
        // حذف کاراکترهای کنترلی (مانند \r، \n، \t)
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);

        // حذف فاصله‌های اضافی
        $text = preg_replace('/\s+/', ' ', $text);

        // جایگزینی کاراکترها بر اساس نگاشت
        $text = strtr($text, $mapping);
        // حذف فاصله‌های اضافی از ابتدا و انتهای متن
        return trim($text);
    }

    private function extractUrl($content)
    {
        $lines = explode(PHP_EOL, $content);
        foreach (array_slice($lines, 0, 3) as $line) {
            if (preg_match('/https?:\/\/[^\s]+/', $line, $matches)) {
                return $matches[0];
            }
        }
        return '';
    }

    public function suggest(Request $request)
    {
        $query = $request->input('query');
        $params = [
            'index' => 'document1',
            'body' => [
                'suggest' => [
                    'title-suggest' => [
                        'prefix' => $query, // عبارت وارد شده
                        'completion' => [
                            'field' => 'suggest',
                            'fuzzy' => true, // برای تطبیق خطای تایپی
                            'size' => 5, // تعداد پیشنهادات
                        ],
                    ],
                ],
            ],
        ];
        $response = $this->client->search($params);

        $suggestions = collect($response['suggest']['title-suggest'][0]['options'])
            ->pluck('_source.title'); // استخراج عناوین

        return response()->json($suggestions);
    }

    private function extractKeywords($text, $limit = 10)
    {
        // حذف علامت‌ها و تبدیل متن به حروف کوچک
        $text = strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', '', $text));

        // جدا کردن کلمات
        $words = explode(' ', $text);

        // حذف کلمات عمومی (Stop Words)
        $stopWords = ['و', 'از', 'به', 'در', 'با', 'برای', 'که', 'این', 'آن']; // مثال برای زبان فارسی
        $filteredWords = array_filter($words, function ($word) use ($stopWords) {
            return !in_array($word, $stopWords) && mb_strlen($word) > 2;
        });

        // شمارش تکرار کلمات
        $wordCounts = array_count_values($filteredWords);

        // مرتب‌سازی کلمات بر اساس بیشترین تکرار
        arsort($wordCounts);

        // انتخاب تعداد محدود کلمات
        return array_slice(array_keys($wordCounts), 0, $limit);
    }

}
