<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ElasticSearch extends Controller
{

    private $client;

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->build();
    }

    public function indexDocuments()
    {
        $files = Storage::files('html');
        try {

            foreach ($files as $key=>$file) {
                if (Storage::exists($file)) {
                    $content = Storage::get($file);
                    $parsed = $this->parseHtml($content);
                    $url = '';
                    $lines = explode(PHP_EOL, $content);

                    foreach (array_slice($lines, 0, 3) as $line) {
                        if (preg_match('/https?:\/\/[^\s]+/', $line, $matches)) {
                            $url = $matches[0];
                            break;
                        }
                    }
                    $params = [
                        'index' => 'documents',
                        'id' => $key,
                        'body' => [
                            'title' => $parsed['title'] ?? 'بدون عنوان',
                            'body' => $parsed['body'] ?? '',
                            'file_path' => $file,
                            'url' => $url, // اضافه کردن url
                        ],
                    ];

                    // ارسال داده‌ها به Elasticsearch
                    $this->client->index($params);
                }

            }
        }catch (Exception $e) {
            echo "Error processing file: " . $e->getMessage() . PHP_EOL;
        }
        return response()->json(['message' => 'Documents indexed successfully!']);
    }

    private function parseHtml($content)
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        $title = $doc->getElementsByTagName('title')->item(0)->textContent ?? null;
        $body = $doc->getElementsByTagName('body')->item(0)->textContent ?? null;

        return [
            'title' => $title,
            'body' => $body,
        ];
    }
    public function search(Request $request)
    {
        $query = $request->input('query');

        $params = [
            'index' => 'documents',
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => ['title', 'body']
                    ]
                ]
            ]
        ];

        $response = $this->client->search($params);

        $results = collect($response['hits']['hits'])->map(function ($hit) {
            return [
                'id' => $hit['_id'], // استخراج id
                'url' => $hit['_source']['url'] ?? null,
                'title' => $hit['_source']['title'],
                'body' => $hit['_source']['body'],
                'file_path' => $hit['_source']['file_path'],
            ];
        });

        return response()->json($results);
    }


}
