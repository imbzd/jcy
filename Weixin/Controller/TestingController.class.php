<?php
/**
 * 随堂测评逻辑层
 * buzhidao
 * 2016-01-11
 */
namespace Weixin\Controller;

class TestingController extends CommonController
{
    public function __construct()
    {
        parent::__construct();

        $this->_course_class = C('USER.course_class');
        $this->assign('courseclass', $this->_course_class);
        
        //获取用户课程学习情况
        $this->usercourseinfo = D('User')->gcUserCourseLearn($this->userinfo['userid'], $this->_course_class);
        $this->assign('usercourseinfo', $this->usercourseinfo);
    }

    //获取课程分类Id
    private function _getClassid()
    {
        $classid = mRequest('classid');

        return $classid;
    }

    //获取课程id
    private function _getCourseid()
    {
        $courseid = mRequest('courseid');

        return $courseid;
    }

    //获取试卷id
    private function _getTestingid()
    {
        $testingid = mRequest('testingid');

        return $testingid;
    }

    public function index()
    {
        $classid = $this->_getClassid();
        $classid = !$classid ? 1 : $classid;
        $this->assign('classid', $classid);

        list($start, $length) = $this->_mkPage();
        $data = D('Testing')->getTesting(null, null, $classid, $this->userinfo['userid'], $start, $length);
        $total = $data['total'];
        $testinglist = $data['data'];

        $this->assign('testinglist', $testinglist);

        $param = array(
            'classid' => $classid,
        );
        $this->assign('param', $param);
        //解析分页数据
        $this->_mkPagination($total, $param);

        $this->display();
    }

    //随堂测评 试卷页
    public function exam()
    {
        $userid = $this->userinfo['userid'];

        $classid = $this->_getClassid();
        $classid = !$classid ? 1 : $classid;
        $this->assign('classid', $classid);

        $courseid = $this->_getCourseid();
        $testingid = $this->_getTestingid();

        $testinginfo = D('Testing')->getTestingByID($courseid, $testingid, $userid);
        if (!is_array($testinginfo) || empty($testinginfo) || !$testinginfo['testingid']) {
            $this->assign('errormsg', '该课程暂无测评试卷！');
            $this->display();
            exit;
        }
        //如果课程未学习 标识为已学习
        $courseid = $testinginfo['courseid'];
        $courseinfo = D('Course')->getCourseByID($courseid, $userid);
        //记录开始学习时间
        if (!$courseinfo['begintime']) {
            M('user_course')->add(array(
                'userid'       => $userid,
                'courseid'     => $courseinfo['courseid'],
                'status'       => 0,
                'begintime'    => TIMESTAMP,
                'completetime' => 0,
            ));
        }
        CR('Course')->scomplete($courseid);

        $testinginfo = D('Testing')->getTestingByID($courseid, $testingid, $userid);
        $testingid = $testinginfo['testingid'];
        if (!$testinginfo['ucstatus']) {
            $this->assign('errormsg', '请先学习该课程！');
            $this->display();
            exit;
        } else if ($testinginfo['utstatus']) {
            header('location:'.__APP__.'?s=Testing/profile&testingid='.$testingid.'&classid='.$classid);
            exit;
        }

        //随机取10道题
        $exams = array();
        $examnum = count($testinginfo['exams'])>10 ? 10 : count($testinginfo['exams']);
        $examsrand = array_rand($testinginfo['exams'], $examnum);
        $i = 0;
        foreach ($examsrand as $key) {
            $exams[$i] = $testinginfo['exams'][$key];
            $exams[$i]['sortno'] = $i+1;
            $i++;
        }
        session('userexams_'.$testingid, $exams);
        $testinginfo['exams'] = $exams;
        $this->assign('testinginfo', $testinginfo);

        $testingprevnextinfo = D('Testing')->getPrevNextTesting($testingid, $classid);
        $this->assign('testingprevnextinfo', $testingprevnextinfo);

        session('testingid_'.$testingid, $testingid);
        $this->display();
    }

    //批阅试卷
    public function check()
    {
        $userid = $this->userinfo['userid'];

        $testingid = $this->_getTestingid();
        $testingid = session('testingid_'.$testingid);
        $testinginfo = D('Testing')->getTestingByID(null, $testingid, $userid);
        $userexams = session('userexams_'.$testingid);
        $testinginfo['exams'] = $userexams;

        if (!is_array($testinginfo) || empty($testinginfo) || $testinginfo['utstatus']) {
            header('location:'.__APP__.'?s=Course/profile&courseid='.$testinginfo['courseid'].'&classid='.$classid);
            exit;
        }

        //获取用户答案
        $exams = mRequest('exams', false);

        //比较答案
        $usertesting = array();
        $usertestingresult = array();
        $rightexam = 0;
        $wrongexam = 0;
        $gotscore = 0;
        foreach ($testinginfo['exams'] as $k=>$exam) {
            if (!isset($exams[$exam['examid']])) {
                // header('location:'.__APP__.'?s=Testing/exam&testingid='.$testingid.'&classid='.$classid);
                // exit;
                $exams[$exam['examid']] = '';
            }

            $useranswer = is_array($exams[$exam['examid']]) ? implode('', $exams[$exam['examid']]) : $exams[$exam['examid']];
            $usertestingresult[] = array(
                'userid' => $userid,
                'testingid' => $testingid,
                'examid' => $exam['examid'],
                'useranswer' => $useranswer,
                'officeanswer' => $exam['answer'],
                'result' => $useranswer==$exam['answer']?1:0
            );
            if ($useranswer == $exam['answer']) {
                $rightexam++;
                $gotscore+=$exam['score'];
            } else {
                $wrongexam++;
            }

            $testinginfo['exams'][$k]['useranswer'] = $useranswer;
            $testinginfo['exams'][$k]['result'] = $useranswer==$exam['answer']?1:0;
        }

        $usertesting = array(
            'userid' => $userid,
            'testingid' => $testingid,
            'status' => 1,
            'rightexam' => $rightexam,
            'wrongexam' => $wrongexam,
            'gotscore' => $gotscore,
            'completetime' => TIMESTAMP,
        );
        $usertestingresult = $usertestingresult;

        if ((int)$gotscore>=(int)$testinginfo['passscore'] && (!isset($this->userinfo['youkeflag']) || !$this->userinfo['youkeflag'])) {
            //及格
            
            M('testing')->startTrans();

            //测评完成人数+1
            $result = M('testing')->where(array('testingid'=>$testingid))->setInc('donenum');
            //添加用户测评信息
            $result1 = M('user_testing')->add($usertesting);
            //添加用户测评结果详细信息
            $result2 = M('user_testing_result')->addAll($usertestingresult);
            //更新用户课程学习状态
            $result3 = M('user_course')->where(array('userid'=>$userid,'courseid'=>$testinginfo['courseid']))->save(array('status'=>2));
            
            if ($result && $result1 && $result2 && $result3) {
                M('testing')->commit();
            } else {
                M('testing')->rollback();
            }
            
            //更新课程作业完成情况
            D('User')->ckUserCourseWork($userid, $testinginfo['courseid']);

            header('location:'.__APP__.'?s=Testing/profile&testingid='.$testingid.'&classid='.$classid);
            exit;
        } else {
            //不及格
            $testinginfo['utstatus'] = 0;
            $testinginfo['rightexam'] = $rightexam;
            $testinginfo['wrongexam'] = $wrongexam;
            $testinginfo['gotscore'] = $gotscore;
            $testinginfo['completetime'] = 0;

            $this->assign('testinginfo', $testinginfo);
            $this->display('Testing/profile');
        }
    }

    //随堂测评结果页
    public function profile()
    {
        $classid = $this->_getClassid();
        $classid = !$classid ? 1 : $classid;
        $this->assign('classid', $classid);

        $userid = $this->userinfo['userid'];

        $courseid = $this->_getCourseid();
        $testingid = $this->_getTestingid();
        $testinginfo = D('Testing')->getTestingByID($courseid, $testingid, $userid);

        if (!is_array($testinginfo) || empty($testinginfo) || !$testinginfo['utstatus']) $this->_gotoIndex();

        $testingid = $testinginfo['testingid'];
        //获取用户做的试卷答案
        $usertestingresult = M('user_testing_result')->where(array('userid'=>$userid,'testingid'=>$testingid))->select();
        foreach ($testinginfo['exams'] as $k=>$exam) {
            $flag = 0;
            foreach ($usertestingresult as $exami) {
                if ($exam['examid'] == $exami['examid']) {
                    $testinginfo['exams'][$k]['useranswer'] = $exami['useranswer'];
                    $testinginfo['exams'][$k]['result'] = $exami['result'];
                    
                    $flag = 1;
                }
            }
            if (!$flag) unset($testinginfo['exams'][$k]);
        }
        $this->assign('testinginfo', $testinginfo);

        $testingprevnextinfo = D('Testing')->getPrevNextTesting($testingid, $classid);
        $this->assign('testingprevnextinfo', $testingprevnextinfo);
        
        $this->display();
    }
}
