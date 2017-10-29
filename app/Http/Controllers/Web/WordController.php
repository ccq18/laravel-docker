<?php

namespace App\Http\Controllers\Web;


use App\Model\Lang\Word;
use App\Model\Lang\WordGroup;
use App\Repositories\WordRepositroy;
use Carbon\Carbon;
use Word\WordListHelper;

class WordController
{
    const PAGE_SIZE = 12;

    public function getNowBook()
    {
        return Word::where('book_id', 1);
    }

    public function index()
    {
        $isAuto = false;
        switch (request('action')) {
            case "last":
                $now = $this->getNow();
                $now = max($now, 1);
                $w = $this->getNowBook()->where('id', '<', $now)->orderByDesc('id')->first();
                if (empty($w)) {
                    $w = Word::first();
                }
                $now = $w->id;
                $this->cacheNow($now);
                break;
            case "next":
                $isAuto = true;
                $now = $this->getNow();
                $w = $this->getNowBook()->where('id', '>', $now)->first();
                $now = $w->id;
                $this->cacheNow($now);
                break;
            default:
                $now = request('word_id');
                if (empty($now)) {
                    $now = $this->getNow();
                }
                $w = Word::where('id', '>=', $now)->first();
                $this->cacheNow($now);
                break;
        }

        return view('words.index', [
            'lastUrl'    => $w->book_id != 1 ? "" : build_url('/words/index', ['action' => 'last']),
            'nextUrl'    => $w->book_id != 1 ? "" : build_url('/words/index', ['action' => 'next']),
            'w'          => $w,
            'isAuto'     => $isAuto,
            'progress'   => $now,
            'delay'      => 2,
            'playNum'    => 3,
            'notCollect' => !$this->isCollect($w->id),

        ]);
    }

    protected function getNow($prefix = '', $default = 0)
    {
        $k = 'word7000' . $prefix . auth()->id();
        $data = \Cache::get($k, $default);

        return $data;
    }

    protected function cacheNow($data, $prefix = '')
    {
        $k = 'word7000' . $prefix . auth()->id();
        \Cache::forever($k, $data);

    }

    public function listWord()
    {
        $this->defaultOrPage();
        $words = $this->getNowBook()->paginate(static::PAGE_SIZE);

        return view('words.list', ['words' => $words, 'paginate' => $words->links()]);
    }

    public function search()
    {
        $search = request('search', '');
        if (resolve(WordRepositroy::class)->isChinese($search)) {
            $words = Word::where('simple_trans', 'like',
                '%' . request('search', '') . '%')->paginate(static::PAGE_SIZE);
        } else {
            $words = Word::where('word', 'like', request('search', '') . '%')->paginate(static::PAGE_SIZE);

        }

        return view('words.list', ['words' => $words, 'paginate' => $words->links()]);

    }

    protected function defaultOrPage()
    {
        $now = $this->getNow();
        $p = request('page');
        if (empty($p)) {
            $n = $this->getNowBook()->where('id', '<', $now)->count();
            $p = floor($n / static::PAGE_SIZE + 1);

        } else {
            $n = $this->getNowBook()->skip(($p - 1) * static::PAGE_SIZE)->first();
            $now = $n ? $n->id : $this->getNowBook()->count();
        }
        // dump($now,$p);
        $this->cacheNow($now);
        \Request::merge(['page' => $p]);
    }

    protected function resetList($start)
    {
        return [
            'now'         => 0,
            'nowId'       => $start,
            'nowAddedId'  => $start,
            'nowReadList' => [],
            'days'        => [],
        ];
    }

    protected function mergeByType($list, $ids, $type)
    {
        foreach ($ids as $id) {
            $list[] = ['id' => $id, 'type' => $type];
        }

        return $list;

    }

    public function getNextWordId2($increment)
    {
        $nowKey = date('Y-m-d');
        $readList = $this->getNow('wordListData2');
        if (empty($readList)) {
            $readList1 = $this->getNow('word-data1');
            $start = 0;
            if (isset($readList1['now-read-id'])) {
                $start = $readList1['now-read-id'];
            }
            $readList = $this->resetList($start);
        }
        $readList['now'] += $increment;
        $readList['now'] = max(0, $readList['now']);
        //初始化
        if (!isset($readList['days'][$nowKey])) {
            $readList['days'][$nowKey] = [];
            $readList['now'] = 0;

        }
        //todo 复习昨日内容
        //每学60个新词整个复习一次
        if (count($readList['days'][$nowKey]) % 60 == 0 && count($readList['days'][$nowKey]) > 0) {
            $readList['nowReadList'] = $this->mergeByType($readList['nowReadList'], $readList['days'][$nowKey],
                'read_again');
        }
        if ($readList['now'] >= count($readList['nowReadList'])) {
            $ids = $this->getNowBook()
                        ->where('id', '>', $readList['nowAddedId'])
                        ->limit(10)
                        ->pluck('id')->all();
            if (!empty($ids)) {
                $readList['nowAddedId'] = max($ids);
                $ids = resolve(WordRepositroy::class)->generateByWords($ids, 30);
                $readList['nowReadList'] = $this->mergeByType($readList['nowReadList'], $ids, 'first_read');
            }

        }
        if (!isset($readList['nowReadList'][$readList['now']])) {
            return ['id' => $readList['nowId'], 'type' => 'read_again'];
        }
        $readList['nowId'] = $readList['nowReadList'][$readList['now']]['id'];
        if (!in_array($readList['nowId'], $readList['days'][$nowKey])) {
            $readList['days'][$nowKey][] =$readList['nowId'];
        }

        $this->cacheNow($readList, 'wordListData2');
        $readList['nowWord'] = $readList['nowReadList'][$readList['now']];

        // dd($readList['nowReadList'][$readList['now']]);
        return $readList;


    }

    public function getLearnedList()
    {
        $readList = $this->getNow('wordListData2');
        $words = [];
        if (!empty($readList['days'])) {
            foreach ($readList['days'] as $day => $ids) {
                $words[$day] = $this->getNowBook()->whereIn('id', $ids)->get();
            }
        }
        krsort($words);

        return view('words.learned-list', ['allWords' => $words]);
    }

    public function readWord()
    {
        $isAuto = false;
        switch (request('action')) {
            case "last":
                $readList = $this->getNextWordId2(-1);
                break;
            case "next":
                $isAuto = true;
                $readList = $this->getNextWordId2(1);

                break;
            default:
                $readList = $this->getNextWordId2(0);
                break;
        }
        $nowId = $readList['nowWord']['id'];
        $w = Word::where('id', '=', $nowId)->first();
        $nowKey = date('Y-m-d');

        return view('words.index', [
            'type'       => $readList['nowWord']['type'],
            'lastUrl'    => build_url('/words/read-word', ['action' => 'last']),
            'nextUrl'    => build_url('/words/read-word', ['action' => 'next']),
            'w'          => $w,
            'isAuto'     => $isAuto,
            'progress'   => count($readList['days'][$nowKey]),
            'sent'       => $w->firstSent(),
            'delay'      => 1,
            'playNum'    => 3,
            'notCollect' => !$this->isCollect($w->id),
        ]);
    }
    // public function readWord1()
    // {
    //     $isAuto = false;
    //     switch (request('action')) {
    //         case "last":
    //             $nowId = $this->getNextWordId(-1);
    //             break;
    //         case "next":
    //             $isAuto = true;
    //             $nowId = $this->getNextWordId(1);
    //
    //             break;
    //         default:
    //             $nowId = $this->getNextWordId(0);
    //             break;
    //     }
    //     $allNum = $this->getNowBook()->where('id', '>', $nowId)->count();
    //     $nowNum = $this->getNowBook()->where('id', '<=', $nowId)->count();
    //     $apr = number_format($nowNum / $allNum * 100, 2);
    //     $w = Word::where('id', '=', $nowId)->first();
    //
    //     return view('words.index', [
    //         'lastUrl'    => build_url('/words/read-word', ['action' => 'last']),
    //         'nextUrl'    => build_url('/words/read-word', ['action' => 'next']),
    //         'w'          => $w,
    //         'isAuto'     => $isAuto,
    //         'progress'   => $apr,
    //         'sent'       => $w->firstSent(),
    //         'delay'      => 1,
    //         'playNum'    => 3,
    //         'notCollect' => !$this->isCollect($w->id),
    //     ]);
    // }

    public function readWord2()
    {

    }

    public function readWordLists()
    {
        $listIds = WordGroup::groupBy('list_id')->get(['list_id'])->pluck('list_id')->all();

        return view('words.read-lists', [
            'listIds' => $listIds,
        ]);
    }

    public function readWordGroups($listId)
    {
        $groups = WordGroup::where('list_id', $listId)
                           ->get([\DB::raw("DISTINCT `group_id`"), 'unit_id'])
                           ->groupBy('unit_id');
        $model = WordGroup::select([\DB::raw("min(list_id) `list_id`"), \DB::raw("min(created_at) `created_at`")])
                          ->groupBy('list_id');

        $latestId = resolve(WordRepositroy::class)->latestId($listId, $model, 'list_id');
        $nextId = resolve(WordRepositroy::class)->nextId($listId, $model, 'list_id');

        return view('words.read-groups', [
            'lastUrl' => $latestId ? url('words/read-list/' . $latestId) : null,
            'nextUrl' => $nextId ? url('words/read-list/' . $nextId) : null,
            'listId'  => $listId,
            'groups'  => $groups,
            'backUrl' => url('words/read-list'),
        ]);
    }


    public function readWordGroupList($listId, $groupId)
    {
        $words = WordGroup::where('group_id', $groupId)
                          ->with('word')
                          ->get()->pluck('word');
        // $nextId = resolve(WordRepositroy::class)->getGroupId($groupId + 1);
        // $lastId = min($groupId - 1, 1);
        $model = WordGroup::query();
        $latestId = resolve(WordRepositroy::class)->latestId($groupId, $model, 'group_id');
        $nextId = resolve(WordRepositroy::class)->nextId($groupId, $model, 'group_id');

        return view('words.read-group-list', [
            'words'   => $words,
            'backUrl' => url("words/read-list/$listId"),
            'lastUrl' => build_url("words/read-list/0/{$latestId}"),
            'nextUrl' => build_url("words/read-list/0/{$nextId}"),
        ]);
    }


    public function addCollect()
    {
        $wordId = request('word_id');
        $collectIds = $this->getNow('collect', []);
        if (!empty($wordId) && !in_array($wordId, $collectIds)) {
            if (Word::where('id', $wordId)->exists()) {
                array_unshift($collectIds, $wordId);
                $this->cacheNow($collectIds, 'collect');
            }

        }

        return $collectIds;
    }

    public function collectList()
    {
        $collectIds = $this->getNow('collect', []);
        $words = $this->getNowBook()->whereIn('id', $collectIds)->orderByDesc('id')->paginate(static::PAGE_SIZE);

        return view('words.list', ['words' => $words, 'paginate' => $words->links()]);
    }

    public function collectDetail()
    {
        $nowId = request('word_id');

        $collectIds = $this->getNow('collect', []);
        $model = $this->getNowBook()->whereIn('id', $collectIds)->orderByDesc('id');
        $w = Word::where('id', $nowId)->orderByDesc('id')->first();
        $word = $w->translate;
        $notCollect = !$this->isCollect($w->id);

        return view('words.index', [
            'lastUrl'    => build_url('/words/collect/detail',
                ['word_id' => resolve(WordRepositroy::class)->latestId($nowId, $model)]),
            'next'       => build_url('/words/collect/detail',
                ['word_id' => resolve(WordRepositroy::class)->nextId($nowId, $model)]),
            'w'          => $w,
            'isAuto'     => request('action') == 'next' ? true : false,
            'word'       => $word,
            'notCollect' => $notCollect,

        ]);
    }

    protected function isCollect($wordId)
    {
        $collectIds = $this->getNow('collect', []);

        return in_array($wordId, $collectIds);
    }

    public function config()
    {
        return view('words.config', []);

    }


}