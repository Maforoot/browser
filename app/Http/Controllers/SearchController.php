<?php

namespace App\Http\Controllers;

use App\Models\History;
use Elastic\Elasticsearch\ClientBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SearchController extends Controller
{
    private $client;

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->build();
    }

    public function search(Request $request)
    {

        $query = $request->input('query');
        $size = $request->input('size', 5); // تعداد نتایج در هر صفحه (پیش‌فرض 10)
        $page = $request->input('page', 1); // شماره صفحه (پیش‌فرض 1)
        $user_id = $request->input('user_id');
        // محاسبه مقدار 'from' که می‌گوید از کدام نتیجه شروع کنیم
        $from = ($page - 1) * $size;
        $params = [
            'index' => 'document1',
            'body' => [
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'match' => [
                                    'title' => [
                                        'query' => $query,
                                        'operator' => 'AND',
                                        'fuzziness' => 2,
                                        'boost' => 45,
                                    ],
                                ],
                            ],
                            [
                                'match_phrase_prefix' => [
                                    'title' => [
                                        'query' => $query,
                                        'boost' => 20,
                                    ],
                                ],
                            ],
                            [
                                'match_phrase' => [
                                    'body' => [
                                        'query' => $query,
                                        'boost' => 15,
                                    ],
                                ],
                            ],
                            [
                                'match' => [
                                    'body' => [
                                        'query' => $query,
                                        'fuzziness' => 2,
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
                                'bool' => [
                                    'should' => [
                                        [
                                            'match_phrase' => [
                                                'body' => [
                                                    'query' => $query,
                                                    'boost' => 30,
                                                ],
                                            ],
                                        ],
                                        [
                                            'multi_match' => [
                                                'query' => $query,
                                                'fields' => ['body'],
                                                'boost' => 20,
                                            ],
                                        ],
                                    ],
                                    'minimum_should_match' => 1,
                                ],
                            ],


                        ],

                    ],
                ],
                'size' => $size, // تعداد نتایج در هر صفحه
                'from' => $from, // از کدام نتیجه شروع کنیم
            ],
        ];

        $start_time = microtime(true);
        $response = $this->client->search($params);
        app(HistoryController::class)->save_history($user_id, $query);
        // ساخت نتایج جستجو برای ارسال به کاربر

        $results = collect($response['hits']['hits'])->map(function ($hit) {
            $highlightedBody = $hit['highlight']['body'][1] ?? 'متنی برای نمایش وجود ندارد';
            return [
                'id' => $hit['_id'],
                'url' => $hit['_source']['url'] ?? null,
                'title' => $hit['_source']['title'],
                'body' => $highlightedBody . '...',
            ];
        });
        $end_time = microtime(true);
        $total_time = round(($end_time - $start_time) * 1000);


        // ارسال نتایج به همراه اطلاعات صفحه‌بندی
        return response()->json([
            'results' => $results,
            'total' => $response['hits']['total']['value'], // تعداد کل نتایج
            'page' => (int)$page,
            'size' => $size,
            'time' => $total_time,
            'search' => $query
        ]);
    }
    public function getSuggestions(Request $request)
    {
        $query = $request->input('query');

        $params = [
            'index' => 'document1',
            'body' => [
                'suggest' => [
                    'document-suggest' => [
                        'prefix' => $query,
                        'completion' => [
                            'field' => 'suggest',
                            'size' => 10, // تعداد پیشنهادات منحصر به فرد
                            'skip_duplicates' => true, // جلوگیری از بازگرداندن مقادیر تکراری
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->client->search($params);
            $suggestions = $response['suggest']['document-suggest'][0]['options'] ?? [];

            return response()->json([
                'suggestions' => array_map(fn($option) => $option['text'], $suggestions),
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching suggestions', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error fetching suggestions'], 500);
        }
    }
}
