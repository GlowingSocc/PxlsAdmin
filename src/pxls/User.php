<?php

namespace pxls;

class User {

    protected $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function checkToken($token, $bypassToken) {
        if($bypassToken !== false && $token == $bypassToken) return true;
        $queryToken = $this->db->prepare("SELECT * FROM sessions WHERE token = :token LIMIT 1");
        $queryToken->bindParam(":token",$token,\PDO::PARAM_STR);
        $queryToken->execute();
        if($queryToken->rowCount() > 0) {
            $token = $queryToken->fetch(\PDO::FETCH_OBJ);
            $user = $this->checkRole($token->who);
            if($user) {
                return $user;
            } else {
                throw new UnauthorizedException('User scope not permitted. Your role is not in the permitted scope. Please talk to your supervisor about this.');
            }
        } else {
            throw new UnauthorizedException('Token not found in database. Seems like your session is invalid.');
        }
        $queryToken->closeCursor();
    }

    protected function checkRole($uid) {
        $allowRoles = ["DEVELOPER", "ADMIN","MODERATOR","TRIALMOD"];

        $getRole = $this->db->prepare("SELECT * FROM users WHERE id = :uid");
        $getRole->bindParam(":uid",$uid,\PDO::PARAM_INT);
        $getRole->execute();
        while($row = $getRole->fetch(\PDO::FETCH_OBJ)) {
            if(in_array($row->role,$allowRoles)) {
                return $row;
            } else {
                return false;
            }
        }
        return false;
    }

    private $userBufferId = [];
    public function getUserById($uid) {
        if (isset($userBufferId[$uid])) {
            return $userBufferId[$uid];
        }
        $getUser = $this->db->prepare("SELECT *,(role='BANNED' OR role='SHADOWBANNED' OR (now() < ban_expiry)) AS 'banned' FROM users WHERE id = :uid LIMIT 1");
        $getUser->bindParam(":uid",$uid,\PDO::PARAM_INT);
        $getUser->execute();
        if($getUser->rowCount() == 1) {
            $usr = $getUser->fetch(\PDO::FETCH_ASSOC);
            $usr["signup_ip"] = inet_ntop($usr["signup_ip"]);
            $usr["last_ip"] = inet_ntop($usr["last_ip"]);
            $userBufferId[$uid] = $usr;
            return $usr;
        } else {
            $userBufferId[$uid] = false;
            return false;
        }
    }

    private $userBufferName = [];
    public function getUserByName($uname) {
        if (isset($userBufferName[$uname])) {
            return $userBufferName[$uname];
        }
        $getUser = $this->db->prepare("SELECT *,(role='BANNED' OR role='SHADOWBANNED' OR (now() < ban_expiry)) AS 'banned' FROM users WHERE username = :uname");
        $getUser->bindParam(":uname",$uname,\PDO::PARAM_STR);
        $getUser->execute();
        if($getUser->rowCount() == 1) {
            $usr = $getUser->fetch(\PDO::FETCH_ASSOC);
            $usr["signup_ip"] = inet_ntop($usr["signup_ip"]);
            $usr["last_ip"] = inet_ntop($usr["last_ip"]);
            $userBufferName[$uname] = $usr;
            return $usr;
        } else {
            $userBufferName[$uname] = false;
            return false;
        }
    }

    public function getUserNotesById($uid) {
        $uid = intval($uid); $notes = []; $reply = 0;
        $getNotes = $this->db->prepare("SELECT id, user_id, target_id, message, timestamp FROM admin_notes WHERE target_id = :uid AND reply_to IS NULL");
        $getNotes->bindParam(":uid", $uid, \PDO::PARAM_INT);
        $getNotes->execute();
        if($getNotes->rowCount() > 0) {
            while($row = $getNotes->fetch(\PDO::FETCH_ASSOC)) {
                $youBetterWorkNow = $this->getUserById($row["user_id"]);
                $row["user_name"] = $youBetterWorkNow["username"];
                $row["replys"] = $this->getNoteReplysById($row["id"]);
                $notes[] = $row;
            }
            return $notes;
        }
        return false;
    }

    public function getBanlogFromDB($uid) {
        $uid = intval($uid);
        $toRet = [];
        $user = $this->getUserById($uid);
        if ($user) {
            $getBansQuery = $this->db->prepare('SELECT b.id, b.when as \'when_timestamp\', from_unixtime(b.when) as \'when\', b.banner as \'banner_id\', COALESCE(u.username, \'console\') as \'banner\', b.banned as \'banned_id\', u1.username as \'banned\', b.ban_expiry as \'ban_expiry_timestamp\', IF(b.ban_expiry, from_unixtime(b.ban_expiry), 0) as \'ban_expiry_date\', IF(b.ban_expiry-b.when > 0, b.ban_expiry-b.when, -1) as \'length\', b.action, b.ban_reason FROM banlogs b LEFT OUTER JOIN users u ON u.id = b.banner INNER JOIN users u1 ON u1.id = b.banned WHERE b.banned = :id');
            $getBansQuery->bindParam(':id', $uid);
            $getBansQuery->execute();
            if ($getBansQuery->rowCount() > 0) {
                $toRet = $getBansQuery->fetchAll(\PDO::FETCH_ASSOC);
            }
        }
        return $toRet;
    }

    public function getBanlogFromAdminLog($uid) {
        $uid = intval($uid);
        $_user = $this->getUserById($uid);
        $toRet = [];
        if ($_user) {
            $uname = $_user["username"]; //not sure if necessary, but forcing a UID here since we can't bindParam to a string for 'LIKE'. forcing a UID means that we do a user lookup before attempting to pull from database. flimsy and probably unnecessary attempt at sanitzation. -Socc
            $uname = str_replace("_", "\\_", $uname);
            $uname = str_replace("%", "\\%", $uname);

            $getBansQuery = $this->db->prepare('SELECT a.*,COALESCE(u.username, "console") AS \'who\',LEFT(message, 2) = \'un\' AS \'is_unban\' FROM admin_log a LEFT OUTER JOIN users u ON u.id = a.userid WHERE a.message LIKE "%ban '.$uname.'" OR a.message LIKE "%ban '.$uname.' %";');
            $getBansQuery->execute();
            if($getBansQuery->rowCount() > 0) {
                $toRet = $getBansQuery->fetchAll(\PDO::FETCH_ASSOC);
            }
        }
        return $toRet;
    }

    public function getNoteReplysById($nid) {
        $nid = intval($nid); $replys = [];
        $getReplys = $this->db->prepare("SELECT id,user_id,target_id,reply_to,message,timestamp FROM admin_notes WHERE reply_to = :nid");
        $getReplys->bindParam(":nid",$nid,\PDO::PARAM_INT);
        $getReplys->execute();
        while($reply = $getReplys->fetch(\PDO::FETCH_ASSOC)) {
            $creator = $this->getUserById($reply["user_id"]);
            $reply["user_name"] = $creator["username"];
            $replys[] = $reply;
        }
        return $replys;
    }

    public function addNoteToUser($target, $message, $reply=null) {
        $target = intval($target); $time = time();
        $addNote = $this->db->prepare("INSERT INTO admin_notes(user_id,target_id,reply_to,message,timestamp) VALUES (:userid,:targetid, :replyto, :message, :timestamp)");

        $addNote->bindParam(":userid", $_SESSION['user_id'], \PDO::PARAM_INT);
        $addNote->bindParam(":targetid", $target, \PDO::PARAM_INT);
        if(!is_null($reply)) {
            $reply = intval($reply);
            $addNote->bindValue(":replyto", $reply, \PDO::PARAM_INT);
        } else {
            $addNote->bindValue(":replyto", $reply, \PDO::PARAM_NULL);
        }
        $addNote->bindParam(":message",$message, \PDO::PARAM_STR);
        $addNote->bindParam(":timestamp",$time, \PDO::PARAM_INT);
        if($addNote->execute()) {
            return true;
        } else {
            return false;
        }
    }

    public function deleteNote($noteid) {
        $noteid = intval($noteid);
        $delNote = $this->db->prepare("DELETE FROM admin_notes WHERE id = :nid");
        $delNote->bindParam(":nid", $noteid, \PDO::PARAM_INT);
        if($delNote->execute()) {
            return true;
        } else {
            return false;
        }
    }

    public function getIPLogForUser($uid) {
        $uid = intval($uid);
        $_user = $this->getUserById($uid);
        $toRet = [];

        if ($_user) { // $usr["last_ip"] = inet_ntop($usr["last_ip"]);
            $query = $this->db->prepare('SELECT * FROM ip_log WHERE user=:user ORDER BY last_used DESC;');
            $query->bindParam(':user', $uid);
            $query->execute();
            if ($query->rowCount() > 0) {
                while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                    $row['ip'] = inet_ntop($row['ip']);
                    $toRet[] = $row;
                }
            }
        }

        return $toRet;
    }

}
