<?php

namespace App\Http\Controllers;

use App\Models\History;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoryController extends Controller
{
    public function save_history($id, $query_searched)
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

    public function return_histories(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();

        $query = History::query();

        $histories = $query->where('user_id', $input['user_id'])->get()->sortByDesc('created_at');

        $histories = $histories->pluck('query_searched');

        if ($histories) {
            return response()->json(['histories' => $histories], 200);
        }
    }

}
