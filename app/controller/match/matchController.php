<?php

namespace app\controller\match;

use app\common\SnowFlake;
use app\controller\admin\adminController;
use app\controller\common\matchCache;
use app\controller\match\Model\MatchRecord;
use app\controller\match\Model\MatchUserModel;
use app\controller\match\Model\Rank;
use app\controller\question\Question;
use app\controller\question\TestSampleModel;
use app\controller\user\UserInfo;
use app\Request;
use think\facade\View;


class matchController
{
    public function index(){
        return View::fetch('matchList');
    }

    /**
     * @Author kaiwei cen
     * @Time  2023-02-08.
     * @param Request $request
     * @return void
     */
    public function addMatch(Request $request){
        $matchModel = new MatchModel();
        $matchModel->save([
            "mid"                   =>      SnowFlake::createID(),
            "match_name"            =>   $request->param("match_name"),
            "match_description"     =>   $request->param("match_description"),
            "creat_time"            =>   date("Y-m-d H:i",time()),
            "start_time"            =>   $request->param("start_time"),
            "persistent_time"       =>   $request->param("persistent_time"),
            "participation_count"   =>   0,
            "match_type"            =>   $request->param("match_type"),
            "img_url"               =>   $request->param("img_url"),
        ]);
    }

    public function addMatchWithForm(Request $request){

        $matchModel = new MatchModel();
        $mid = SnowFlake::createID();
        $matchModel->save([
            "mid"                   =>   $mid,
            "match_name"            =>   $request->param("match_name"),
            "match_description"     =>   $request->param("match_description"),
            "creat_time"            =>   date("Y-m-d H:i",time()),
            "start_time"            =>   $request->param("start_time"),
            "persistent_time"       =>   $request->param("persistent_time"),
            "participation_count"   =>   0,
            "match_type"            =>   $request->param("match_type"),
            "img_url"               =>   $request->param("img_url"),
            "state"                 =>   0
        ]);

        $qids = $request->param("question_choice");

        foreach ($qids as $key => $qid){
            $matchQuestionModel = new MatchQuestionModel();
            $save = $matchQuestionModel->save([
                "id" => null,
                "mid" => $mid,
                "qid" => $qid
            ]);


        }
        header("Location:../admin.adminController/toAdminMenu");
    }

    public function getMatchList($uid){
        $matchModel = new MatchModel();
        $matchList = $matchModel->order("mid","desc")->select();

        $matchUserModel = new MatchUserModel();
        $participateMatch = $matchUserModel->where("uid", $uid)->select();

        $participateList = [];
        foreach ($matchList as $key => $match){
            $flag = false;
            foreach ($participateMatch as $key => $participate){
                if($match->mid == $participate->mid){
                    $flag = true;
                    break;
                }
            }
            $participateList[] = $flag;
        }



        return View::fetch('matchList',["matchList" => $matchList,"uid"=>$uid,"participateList" => $participateList]);
    }

    public function matchDetail($uid,$mid){

        $matchModel = new MatchModel();
        $match = $matchModel->find($mid);


        $matchQuestionModel = new MatchQuestionModel();
        $questionsId = $matchQuestionModel->where("mid",$mid)->select();
        $ids = [];
        foreach ($questionsId as $key => $id){
            $ids[] = $id->qid;
        }
        $questions = Question::select($ids);

        $matchUserModel = new MatchUserModel();

        $count = $matchUserModel->where("mid", $mid)->where("uid", $uid)->count();
        $state = $count == 1;

        return View::fetch("matchDetail",["match" => $match,"questionList" => $questions,"state"=>$state]);
    }

    public function changeMatchInfo(Request  $request){
        $mid = $_POST["mid"];
        $match = MatchModel::find($mid);
        $match->match_name          = $_POST["match_name"];
        $match->match_description   = $_POST["match_description"];
        $match->start_time          = $_POST["start_time"];
        $match->match_type          = $_POST["match_type"];
        $match->persistent_time     = $_POST["persistent_time"];
        $match->img_url             = $_POST["img_url"];

        $match->save();

        MatchQuestionModel::where("mid",'=',$mid)->delete();



        $qids = $_POST["question_choice"];

        foreach ($qids as $key => $qid){
            $matchModel = new MatchQuestionModel();
            $matchModel->save([
                "mid" => $mid,
                "qid" => $qid
            ]);

        }
        header("Location:../admin.adminController/getMatch?page=1");
    }

    public function participateMatch($uid,$mid){
        $matchUserModel = new MatchUserModel();
        $matchUserModel->save([
           "uid"    =>      $uid,
           "mid"    =>      $mid
        ]);

        $matchModel = new MatchModel();
        $match = $matchModel->where("mid", $mid)->find();
        $match->participation_count ++;
        $match->save();
        header("Location:../match.matchController/getMatchList?uid=" . $uid);
    }

    public static function scanMatch(){
        echo "?????????????????? \n";
        self::startMatch();
        self::endMatch();
    }

    public static function startMatch(){
        $matchModel = new MatchModel();
        $matchs = $matchModel->where("state", 0)->select();

        foreach ($matchs as $key => $match){
            echo " ?????? :" . $match->match_name . " ???????????? : " . date("Y-m-d H:i",time()) . " ,???????????? : " . str_replace("T"," ",$match->start_time) . " \n";

            if(date("Y-m-d H:i",time()) == str_replace("T"," ",$match->start_time)){
                echo "??????????????? !\n";
                $match->state = 1;
                $match->save();
//               ???????????????redis
            }else{
                echo "??????????????????\n";
            }
        }
    }

    public static function endMatch(){
        $matchModel = new MatchModel();
        $matchs = $matchModel->where("state", 1)->select();

        foreach ($matchs as $key => $match){
            $startTime = str_replace("T"," ",$match->start_time);
            $persistentTime = number_format($match->persistent_time);
            $endTime = date("Y-m-d H:i",strtotime("$startTime + $persistentTime min"));
            echo " ?????? :" . $match->match_name . " ???????????? : " . date("Y-m-d H:i",time()) . " ,???????????? : " . $endTime . " \n";

            if(date("Y-m-d H:i",time()) == $endTime){
                echo "??????????????? !\n";
                $match->state = 2;
                $match->save();
//                ???????????????????????????
                $matchQuestionModel = new MatchQuestionModel();
                $questionList = $matchQuestionModel->where("mid", $match->mid)->column("qid");
                matchCache::getMatchInfo($match->mid,$questionList);
            }else{
                echo "??????????????????\n";
            }
        }
    }

    public static function resultMatch($mid){
        $matchRecord = new MatchRecord();
        $matchRecords = $matchRecord->where("mid", $mid)->select();

        $matchQuestionModel = new MatchQuestionModel();

        $qids = $matchQuestionModel->where("mid", $mid)->column("qid");
        $initState = [];
        foreach ($qids as $key => $qid){
            $initState[$qid] = 0;
        }
        $rank = [];

        foreach ($matchRecords as $key => $record){
            if(!array_key_exists($record->uid,$rank)){
                $userInfo = new UserInfo();
                $user_name = $userInfo->where("uid", $record->uid)->column("user_name")[0];
                $rank[$record->uid] = new Rank($mid,$record->uid,$user_name,0,$initState);
            }
            if($record->state == 40000){
//                ??????
                $rank[$record->uid]->score++;
                $rank[$record->uid]->states[$record->qid] = 1;
            }else if($rank[$record->uid]->states[$record->qid] == 0){
//                ??????????????????????????????????????????
                $rank[$record->uid]->states[$record->qid] = 2;
            }
        }

        $score = array_column($rank,'score');
        array_multisort($score,SORT_DESC,$rank);


        return View::fetch("resultMatch",["rank"=>$rank]);
    }
}