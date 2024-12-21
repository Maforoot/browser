<?php

namespace App\Http\Controllers;

use App\Models\History;
use Elastic\Elasticsearch\ClientBuilder;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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


        $response = $this->client->search($params);
        $this->history($user_id, $query);
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

        // ارسال نتایج به همراه اطلاعات صفحه‌بندی
        return response()->json([
            'results' => $results,
            'total' => $response['hits']['total']['value'], // تعداد کل نتایج
            'page' => (int)$page,
            'size' => $size,
        ]);
    }

    private function history($id, $query_searched)
    {
        $validator = Validator::make(['id' => $id, 'query_searched' => $query_searched], [
            'query_searched' => 'required|string',
            'id' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();

        $history = History::create([
            'user_id' => $input['id'],
            'query_searched' => $input['query_searched'],
        ]);
        if ($history) {
            return response()->json(['successful' => true], 200);
        }
    }

}
