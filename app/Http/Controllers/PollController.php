<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Validator;
use DB;
use Cache;
use Config;

use App\Poll;
use App\PollOption;
use App\PollVote;
use App\PollVotingCode;

class PollController extends Controller
{
    public function __invoke(Request $request)
    {
        if($request->format() == 'json') {
            return response()->json(['timezone' => Config::get('app.timezone')]);
        } else {
            return view('create_poll');
        }
    }

    public function create(Request $request)
    {
        if($request->has('options')) {
            $request['options'] = array_filter($request->input('options'), function($i) { return $i !== null; });
        }

        $request['allow_multiple_answers'] = $request->has('allow_multiple_answers');
        $request['hide_results_until_closed'] = $request->has('hide_results_until_closed');
        $request['automatically_close_poll'] = $request->has('automatically_close_poll');
        $request['set_admin_password'] = $request->has('set_admin_password');

        $validatedInput = $request->validate([
            'question' => 'required|string',
            'options' => 'required|min:2|distinct',
            'allow_multiple_answers' => 'required|boolean',
            'hide_results_until_closed' => 'required|boolean',
            'automatically_close_poll' => 'required|boolean',
            'automatically_close_poll_datetime' => 'required_if:automatically_close_poll,true|date|after:now',
            'set_admin_password' => 'required|boolean',
            'admin_password' => 'required_if:set_admin_password,true|nullable|string',
            'duplicate_vote_checking' => 'required|in:none,cookies,codes',
            'number_of_codes' => 'required_if:duplicate_vote_checking,codes|integer|min:2'
        ]);

        $poll = new Poll;
        $poll->question = $validatedInput['question'];
        $poll->duplicate_vote_checking = $validatedInput['duplicate_vote_checking'];
        $poll->allow_multiple_answers = $validatedInput['allow_multiple_answers'];
        $poll->hide_results_until_closed = $validatedInput['hide_results_until_closed'];
        $poll->created_at = Carbon::now();
        
        if($validatedInput['automatically_close_poll']) {
            $poll->closes_at = Carbon::parse($validatedInput['automatically_close_poll_datetime']);
        }
        
        if($validatedInput['set_admin_password']) {
            $poll->admin_password = $validatedInput['admin_password'];
        }
        
        $poll->save();

        $idToUse = Poll::where('created_at', $poll->created_at)
                            ->first()
                            ->id;

       foreach ($validatedInput['options'] as $key => $value) {
            PollOption::create([
                'text' => $value,
                'poll_id' => $idToUse,
            ]);
        }

        if($poll->duplicate_vote_checking == 'codes') {

            $codes = $poll->createVotingCodes($validatedInput['number_of_codes']);

        }

        $newPoll = Poll::find($idToUse);

        $newPoll->load('options', 'votes', 'voting_codes');

        return redirect()
                        ->action('PollController@view', ['poll' => $newPoll])
                        ->with('new', true);
    }

    public function view(Request $request, Poll $poll)
    {
        if($poll->closed) {
            return redirect()->action('PollController@viewResults', ['poll' => $poll])->with('alreadyClosed', true);
        }

        $new = $request->session()->pull('new', false);

        if($request->format() == 'json') {
            $data = [
                'id' => $poll->id,
                'new' => $new,
                'question' => $poll->question,
                'options' => $poll->options->map(function($o) { return $o->makeHidden('poll_id'); }),
                'multipleAnswersAllowed' => $poll->allow_multiple_answers
            ];

            if($new && $poll->duplicate_vote_checking == 'codes') {
                $data['votingUrls'] = $poll->voting_codes()->get()->map(function($c) use($poll) { return action('PollController@view', ['poll' => $poll, 'code' => $c]); });
            }

            return response()->json($data);
        } else {
            return view('view_poll')
                ->with('poll', $poll)
                ->with('new', $new)
                ->with('hasVoted', $this->hasVoted($request, $poll))
                ->with('code', $request->query('code', null));
        }
    }

    public function viewResults(Request $request, Poll $poll)
    {
        $voted = $request->session()->pull('voted', false);
        $alreadyClosed = $request->session()->pull('alreadyClosed', false);

        $this->createPieChart($poll);

        if($request->format() == 'json') {
            $data = [
                'id' => $poll->id,
                'voted' => $voted,
                'alreadyClosed' => $alreadyClosed,
                'resultsVisible' => $poll->results_visible
            ];

            if($poll->results_visible) {
                $data['results'] = $poll->options->map(function($o) {
                    $array = $o->makeHidden('poll')->makeHidden('poll_id')->append('vote_count')->toArray();

                    //I really shouldn't have to do this...
                    $array['voteCount'] = $array['vote_count'];
                    unset($array['vote_count']);

                    return $array;
                });
            }

            return response()->json($data);
        } else {
            return view('view_poll_results')
                ->with('poll', $poll)
                ->with('voted', $voted)
                ->with('alreadyClosed', $alreadyClosed);
        }
    }

    private static function imageToDataUri($image)
    {
        ob_start();

        imagepng($image);
        $dataUri = "data:image/png;base64," . base64_encode(ob_get_contents());

        ob_end_clean();

        return $dataUri;
    }

    private function createPieChart(Poll $poll)
    {
        $voteCount = $poll->votes->count();

        if(Cache::has($poll->id) && Cache::get($poll->id)['vote_count'] == $voteCount) {
            return;
        }

        $baseColours = [[0xE8, 0x96, 0x3F], [0xAD, 0x3F, 0xE8], [0x3F, 0xE8, 0x6F], [0xE8, 0xE3, 0x3F], [0x3F, 0x64, 0xEB], [0xE8, 0x3F, 0x65], [0x3F, 0xE8, 0xDB]];
        shuffle($baseColours);

        $supersamplingFactor = 8;

        $width = 512 * $supersamplingFactor;
        $height = 512 * $supersamplingFactor;
        $padding = 16 * $supersamplingFactor;

        $chartWidth = $width - 2 * $padding;
        $chartHeight = $height - 2 * $padding;

        $colourSquareSize = 13;

        $pieChart = imagecreatetruecolor($width, $height);
        $transparent = imagecolorallocatealpha($pieChart, 0xFF, 0xFF, 0xFF, 0x7F);
        imagefill($pieChart, 0, 0, $transparent);
        imagesavealpha($pieChart, true);
        imageantialias($pieChart, true);

        $colourSquareUris = [];

        $startDegrees = 0;
        $sortedOptions = $poll->options->sortByDesc(function($option) { return $option->vote_count; });
        $nonZeroOptions = $sortedOptions->filter(function($option) { return $option->vote_count > 0; })->values();
        debug($nonZeroOptions);
        for($i = 0; $i < $nonZeroOptions->count(); $i++) {
            $option = $nonZeroOptions[$i];

            //TODO: Fix gaps
            $degrees = round($option->vote_count / $voteCount * 360);
            $endDegrees = min($startDegrees + $degrees, 360);

            $c = function($j) use($i, $baseColours, $nonZeroOptions) {
                return $baseColours[$i % count($baseColours)][$j]
                    + (255 - $baseColours[$i % count($baseColours)][$j])
                    * floor($i / count($baseColours)) / (floor($nonZeroOptions->count() / count($baseColours)) + 1);
            };
            $colour = imagecolorallocate($pieChart, $c(0), $c(1), $c(2));

            debug([$option->text, [$startDegrees, $endDegrees], [$c(0), $c(1), $c(2)]]);

            imagefilledarc($pieChart, $width / 2, $height / 2, $chartWidth, $chartHeight, $startDegrees, $endDegrees, $colour, IMG_ARC_PIE);

            $colourSquare = imagecreatetruecolor($colourSquareSize, $colourSquareSize);
            $colourSquareColour = imagecolorallocate($colourSquare, $c(0), $c(1), $c(2));
            imagefill($colourSquare, 0, 0, $colourSquareColour);
            $colourSquareUris[$option->id] = PollController::imageToDataUri($colourSquare);

            $startDegrees = $endDegrees;
        }

        debug($colourSquareUris);

        $resized = imagecreatetruecolor($width / $supersamplingFactor, $height / $supersamplingFactor);
        imagecolortransparent($resized, imagecolorallocatealpha($resized, 0, 0, 0, 0x7F));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $pieChart, 0, 0, 0, 0, $width / $supersamplingFactor, $height / $supersamplingFactor, $width, $height);
        $pieChart = $resized;

        $dataUri = PollController::imageToDataUri($pieChart);

        Cache::put($poll->id, ['vote_count' => $voteCount, 'pie_chart' => $dataUri, 'colour_squares' => $colourSquareUris], now()->addDays(1));
    }

    public function hasVoted(Request $request, Poll $poll)
    {
        if($poll->duplicate_vote_checking == 'cookies') {
            if($request->session()->exists($poll->id)) {
                return true;
            }
        } else if($poll->duplicate_vote_checking == 'codes') {
            $code = PollVotingCode::find($request->query('code'));

            if($code == null || $code->used) {
                return true;
            }
        }

        return false;
    }

    public function vote(Request $request, Poll $poll)
    {
        if($poll->closed) {
            return redirect()->action('PollController@viewResults', ['poll' => $poll])->with('alreadyClosed', true);
        }

        if($this->hasVoted($request, $poll)) {
            return redirect()->action('PollController@view', ['poll' => $poll]);
        }

        if($poll->allow_multiple_answers) {
            $validatedInput = $request->validate([
                'options' => 'required|distinct',
            ]);
        } else {
            $validatedInput = $request->validate([
                'options' => 'required|distinct|min:1|max:1',
            ]);
        }

        DB::beginTransaction();
        foreach($validatedInput['options'] as $option)
        {
            if($poll->options()->find($option) == null) {
                DB::rollBack();

                return redirect()->action('PollController@view', ['poll' => $poll]);
            }

            $vote = new PollVote;
            $vote->poll_option_id = $option;
            $poll->votes()->save($vote);
        }

        if($poll->duplicate_vote_checking == 'cookies') {
            $request->session()->put($poll->id, null);
        } else if($poll->duplicate_vote_checking == 'codes') {
            $code = PollVotingCode::find($request->query('code'));

            $code->used = true;
            $code->save();
        }
        DB::commit();

        return redirect()->action('PollController@viewResults', ['poll' => $poll])->with('voted', true);
    }

    public function admin(Request $request, Poll $poll)
    {
        $changed = $request->session()->pull('changed', false);
        $extraCodes = $request->session()->pull('extraCodes', null);

        if($poll->admin_password == null || $request->query('password') != $poll->admin_password) {
            return redirect()->action('PollController@viewResults', ['poll' => $poll]);
        }

        if($request->format() == 'json') {
            return response()->json([
                "id" => $poll->id,
                "changed" => $changed,
                "extraVotingUrls" => collect($extraCodes)->map(function($c) use($poll) { return action('PollController@view', ['poll' => $poll, 'code' => $c]); })
            ]);
        } else {
            return view('edit_poll')->with('poll', $poll)->with('changed', $changed)->with('extraCodes', $extraCodes);
        }
    }

    public function edit(Request $request, Poll $poll)
    {
        if($poll->admin_password == null || $request->query('password') != $poll->admin_password) {
            return redirect()->action('PollController@viewResults', ['poll' => $poll]);
        }

        if($request->has('extra_codes')) {
            if($poll->duplicate_vote_checking != 'codes') {
                return redirect()->action('PollController@view', ['poll' => $poll]);
            }

            $validatedInput = $request->validate([
                'extra_codes' => 'integer|min:1'
            ]);

            $codes = $poll->createVotingCodes($validatedInput['extra_codes']);

            return redirect()
                ->action('PollController@admin', ['poll' => $poll, 'password' => $poll->admin_password])
                ->with('extraCodes', $codes);
        } else if($request->has('close_now')) {
            $poll->closes_at = Carbon::now();
            $poll->save();

            return redirect()->action('PollController@viewResults', ['poll' => $poll]);
        } else {
            $request['allow_multiple_answers'] = $request->has('allow_multiple_answers');
            $request['hide_results_until_closed'] = $request->has('hide_results_until_closed');
            $request['automatically_close_poll'] = $request->has('automatically_close_poll');
            $request['set_admin_password'] = $request->has('set_admin_password');

            $validatedInput = $request->validate([
                'hide_results_until_closed' => 'required|boolean',
                'automatically_close_poll' => 'required|boolean',
                'automatically_close_poll_datetime' => 'required_if:automatically_close_poll,true|date|after:now',
                'set_admin_password' => 'required|boolean',
                'admin_password' => 'required_if:set_admin_password,true|nullable|string',
            ]);

            $poll->hide_results_until_closed = $validatedInput['hide_results_until_closed'];
            $poll->closes_at = $validatedInput['automatically_close_poll'] ? Carbon::parse($validatedInput['automatically_close_poll_datetime']) : null;
            $poll->admin_password = $validatedInput['set_admin_password'] ? $validatedInput['admin_password'] : null;
            $poll->save();

            if($poll->closed || $poll->admin_password == null) {
                return redirect()->action('PollController@viewResults', ['poll' => $poll]);
            } else {
                return redirect()
                    ->action('PollController@admin', ['poll' => $poll, 'password' => $poll->admin_password])
                    ->with('changed', true);
            }
        }
    }
}
