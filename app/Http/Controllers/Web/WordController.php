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
            'lastUrl'    => $w->book_id!=1?"":build_url('/words/index', ['action' => 'last']),
            'nextUrl'    => $w->book_id!=1?"":build_url('/words/index', ['action' => 'next']),
            'w'          => $w,
            'isAuto'     => $isAuto,
            'progress'   => $now,
            'delay'      => 1,
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

    protected function getNextWordId($increment)
    {
        $nowKey = date('Y-m-d');
        $lastKey = Carbon::yesterday()->format('Y-m-d');
        $readList = $this->getNow('word-data1',
            [
                'now'         => 0,
                'now-read-id' => 1,
                'days'        => [],
            ]);
        $now = $readList['now'];
        $nowReadId = $readList['now-read-id'];
        $now += $increment;
        $now = max(0, $now);
        //初始化今日数据
        if (!isset($readList['days'][$nowKey])) {
            $today = [];
            $today['want-read-list'] = [];
            $today['read-list'] = [];
            $today['have-read-list'] = [];
            $today['today-study-list'] = [];
            $today['today-start-id'] = $nowReadId;
            //今日复习列表由昨日的复习列表决定
            if (isset($readList['days'][$lastKey]['read-list'])) {
                $today['want-read-list'] = collect($readList['days'][$lastKey]['want-read-list'])->map(function ($v) use
                (
                    $now
                ) {
                    $v['at'] -= $now;

                    return $v;
                })->all();
                //初始化基础数据
            } else {
                if (empty($readList['now'])) {
                    $nowReadId = $this->getNow();
                    $readeds = $this->getNowBook()->where('id', '<', $nowReadId)->get();
                    $today['want-read-list'] = $readeds->map(function ($vv) {
                        $v['at'] = $vv->id * 10 + 1000;
                        $v['increment'] = 256;
                        $v['id'] = $vv->id;

                        return $v;

                    })->all();
                }
            }

            $now = 0;
        } else {
            $today = $readList['days'][$nowKey];
        }

        if (count($today['read-list']) <= $now) {
            $want = collect($today['want-read-list']);
            $wanted = $want->filter(function ($v) use ($now) {
                return $v['at'] <= $now;
            });
            $noWanted = $wanted->slice(5);
            $wanted = $wanted->slice(0, 5);
            $wanted = $wanted->map(function ($v) use ($now) {
                $v['increment'] *= 4;
                if ($v['increment'] >= 256) {
                    $v['increment'] *= 3;
                }
                $v['at'] = $now + $v['increment'];

                return $v;
            });
            $today['want-read-list'] = $want->filter(function ($v) use ($now) {
                return $v['at'] > $now;
            })->merge($wanted)->merge($noWanted)->all();
            $today['read-list'] = array_merge($today['read-list'], $wanted->pluck('id')->all());
            $next = $this->getNowBook()->where('id', '>', $nowReadId)->first();
            if (!empty($next)) {
                $nowReadId = $next->id;
                $today['want-read-list'][] = [
                    'increment' => 4,
                    'at'        => $now + 8,
                    'id'        => $nowReadId
                ];
                $today['read-list'][] = $nowReadId;
            }

        }
        $nextId = isset($today['read-list'][$now]) ? $today['read-list'][$now] : $nowReadId;
        if ($today['today-start-id'] <= $nextId && !in_array($nextId, $today['today-study-list'])) {
            $today['today-study-list'][] = $nextId;
        }
        $today['have-read-list'][] = $nextId;


        $readList['now'] = $now;
        $readList['now-read-id'] = $nowReadId;
        $readList['days'][$nowKey] = $today;

        $this->cacheNow($readList, 'word-data1');

        return $nextId;
    }


    public function readWord()
    {
        $isAuto = false;
        switch (request('action')) {
            case "last":
                $nowId = $this->getNextWordId(-1);
                break;
            case "next":
                $isAuto = true;
                $nowId = $this->getNextWordId(1);

                break;
            default:
                $nowId = $this->getNextWordId(0);
                break;
        }
        $allNum = $this->getNowBook()->where('id', '>', $nowId)->count();
        $nowNum = $this->getNowBook()->where('id', '<=', $nowId)->count();
        $apr = number_format($nowNum / $allNum * 100, 2);
        $w = Word::where('id', '=', $nowId)->first();

        return view('words.index', [
            'lastUrl'    => build_url('/words/read-word', ['action' => 'last']),
            'nextUrl'    => build_url('/words/read-word', ['action' => 'next']),
            'w'          => $w,
            'isAuto'     => $isAuto,
            'progress'   => $apr,
            'sent'       => $w->firstSent(),
            'delay'      => 1,
            'playNum'    => 3,
            'notCollect' => !$this->isCollect($w->id),
        ]);
    }

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