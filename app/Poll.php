<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Webpatser\Uuid\Uuid;
use Carbon\Carbon;

class Poll extends Model
{
    public $timestamps = false;

    public $keyType = "string";

    public $incrementing = false;

    public function __construct()
    {
        parent::__construct();

        $this->id = Poll::createId();
    }

    private static function createId()
    {
        return Uuid::generate()->string;
    }

    public function options()
    {
        return $this->hasMany('App\PollOption');
    }

    public function votes()
    {
        return $this->hasMany('App\PollVote');
    }

    public function voting_codes()
    {
        return $this->hasMany('App\PollVotingCode');
    }

    public function createVotingCodes($n)
    {
        $codes = [];
        for($i = 0; $i < $n; $i++) {
            $codes[] = new PollVotingCode;
        }

        $this->voting_codes()->saveMany($codes);

        return $codes;
    }

    public function getClosedAttribute()
    {
        return ($this->closes_at != null && Carbon::parse($this->closes_at)->isPast()) ||
            ($this->duplicate_vote_checking == 'codes' && $this->voting_codes()->where('used', false)->count() == 0);
    }

    public function getResultsVisibleAttribute()
    {
        return !$this->hide_results_until_closed || $this->closed;
    }
}
