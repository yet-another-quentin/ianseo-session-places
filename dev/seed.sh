#!/bin/sh
# Prepares the test IANSEO started by docker-compose.yml: schema and an outdoor competition "SP26" (FR rules, 70m round,
# TAE: CL at 70 m, CO at 50 m) with 3 sessions and fictitious entries. Session 1 holds 2 targets × 2 archers and is full.
# With SELFREG_DIR mounted, it also adds pending online requests and bow type quotas of the SelfRegistration module.
# Usage: IANSEO_DIR=/path/to/ianseo-copy ./seed.sh   (from the dev/ folder, containers running)
set -e
: "${IANSEO_DIR:?copy of the IANSEO sources}"
cd "$(dirname "$0")"
URL=${URL:-http://localhost:8091}
sql() { docker compose exec -T db mariadb -uianseo -pianseo ianseo "$@"; }

if [ ! -f "$IANSEO_DIR/Common/config.inc.php" ]; then
  cat > "$IANSEO_DIR/Common/config.inc.php" <<'PHP'
<?php
$CFG->W_HOST = 'db';
$CFG->W_USER = 'ianseo';
$CFG->W_PASS = 'ianseo';
$CFG->R_HOST = 'db';
$CFG->R_USER = 'ianseo';
$CFG->R_PASS = 'ianseo';
$CFG->DB_NAME = 'ianseo';
$CFG->ROOT_DIR = '/';
PHP
fi

until sql -e 'select 1' >/dev/null 2>&1; do sleep 1; done
if ! sql -e 'show tables like "Tournament"' | grep -q Tournament; then
  sql < "$IANSEO_DIR/Install/install.sql"
fi

# IANSEO upgrades its schema when its home page is first opened
curl -s -o /dev/null "$URL/"

if ! sql -N -e "select count(*) from Tournament where ToCode='SP26'" | grep -q '^[1-9]'; then
  jar=$(mktemp)
  curl -s -c "$jar" -b "$jar" -o /dev/null "$URL/Tournament/index.php?New=1"
  curl -s -c "$jar" -b "$jar" -o /dev/null "$URL/Tournament/index.php?New=1" \
    --data-urlencode 'Command=SAVE' --data-urlencode 'd_ToCode=SP26' --data-urlencode 'd_ToName=Concours TAE de Cestas (test)' \
    --data-urlencode 'd_ToCommitee=0333333' --data-urlencode 'd_ToComDescr=Archers de Cestas' --data-urlencode 'd_ToWhere=Cestas' \
    --data-urlencode 'd_Rule=FR' --data-urlencode 'd_ToType=3' --data-urlencode 'd_SubRule=9' --data-urlencode 'd_ToCountry=FRA' \
    --data-urlencode 'd_ToTimeZone=+01:00' --data-urlencode 'd_ToIocCode=FRA' \
    --data-urlencode 'xx_ToWhenFromYear=2027' --data-urlencode 'xx_ToWhenFromMonth=5' --data-urlencode 'xx_ToWhenFromDay=8' \
    --data-urlencode 'xx_ToWhenToYear=2027' --data-urlencode 'xx_ToWhenToMonth=5' --data-urlencode 'xx_ToWhenToDay=9'
  rm -f "$jar"
fi

# Sessions and entries (EnAthlete=1), one Qualifications row per entry. Categories: division + class of the FR rules.
entries() {   # session division class count
  i=0
  while [ $i -lt "$4" ]; do
    echo "insert into Entries (EnTournament,EnCode,EnIocCode,EnName,EnFirstName,EnDivision,EnClass,EnAgeClass,EnAthlete,EnStatus,EnIndClEvent,EnTeamClEvent,EnIndFEvent,EnTeamFEvent,EnTimestamp)
      values (@t,concat('SP',lpad(@n:=@n+1,5,'0')),'FRA','ARCHER',concat('Test ',@n),'$2','$3','$3',1,0,1,1,1,1,now());"
    echo "insert into Qualifications (QuId,QuSession) values (last_insert_id(),$1);"
    i=$((i + 1))
  done
}
{
  cat <<'SQL'
set @t = (select max(ToId) from Tournament where ToCode='SP26');
update Tournament set ToIocCode='FRA', ToNumSession=3 where ToId=@t;
delete from Session where SesTournament=@t;
insert into Session (SesTournament,SesOrder,SesType,SesName,SesTar4Session,SesAth4Target,SesFirstTarget,SesStatus,SesDtStart,SesDtEnd,SesOdfCode,SesOdfPeriod,SesOdfVenue,SesOdfLocation,SesLocation) values
 (@t,1,'Q','Samedi matin',2,2,1,'','2027-05-08 09:00','2027-05-08 12:00','','','','',''),
 (@t,2,'Q','Samedi après-midi',16,4,1,'','2027-05-08 14:00','2027-05-08 18:00','','','','',''),
 (@t,3,'Q','',16,4,1,'','2027-05-09 09:00','2027-05-09 12:00','','','','','');
delete from Qualifications where QuId in (select EnId from Entries where EnTournament=@t);
delete from Entries where EnTournament=@t;
set @n = 0;
SQL
  entries 1 CL S1H 3
  entries 1 CO S1F 1
  entries 2 CL S1H 18
  entries 2 CL U18F 6
  entries 2 CO S2H 7
  entries 2 CO S1F 4
  entries 3 CL U15H 5
  entries 3 CO S3H 2
} | sql

# Pending online requests and quotas (SelfRegistration), when its table exists
if sql -N -e "show tables like 'SelfRegRequests'" | grep -q SelfRegRequests; then
  sql <<'SQL'
set @t = (select max(ToId) from Tournament where ToCode='SP26');
delete from SelfRegRequests where SrTournament=@t;
insert into SelfRegRequests (SrTournament,SrGroup,SrCode,SrDivision,SrClass,SrSession,SrStatus,SrCreated) values
 (@t,'g1','900001A','CO','S1H',2,0,now()), (@t,'g2','900002B','CO','S1F',2,0,now()), (@t,'g3','900003C','CL','S2H',2,0,now()),
 (@t,'g4','900004D','CL','S1H',2,3,now());
set @j = '{"open":true,"sessions":[1,2,3],"quotas":{"2":{"CO":12,"CL":30}}}';
insert into ModulesParameters (MpModule, MpParameter, MpTournament, MpValue)
  values ('SelfRegistration', 'settings', @t, concat('s:', length(@j), ':"', @j, '";'))
  on duplicate key update MpValue=values(MpValue);
SQL
else
  echo "SelfRegRequests missing: open the SelfRegistration page once (with SELFREG_DIR mounted), then run seed.sh again."
fi

# Test logo (left), drawn with GD
docker compose exec -T web php -r '
$im = imagecreatetruecolor(240, 240);
imagefill($im, 0, 0, imagecolorallocate($im, 47, 111, 79));
imagestring($im, 5, 98, 112, "CLUB", imagecolorallocate($im, 255, 255, 255));
ob_start(); imagepng($im); $l = ob_get_clean();
$db = new mysqli("db", "ianseo", "ianseo", "ianseo");
$st = $db->prepare("update Tournament set ToImgL=? where ToCode=\"SP26\"");
$st->bind_param("s", $l);
$st->execute();
'
sql -e "select ToId, ToCode, ToType, ToNumDist from Tournament where ToCode='SP26'; select TdClasses, Td1, Td2 from TournamentDistances td join Tournament t on TdTournament=ToId and TdType=ToType where ToCode='SP26'"

echo "Admin:  $URL/  (open SP26, then Participants › Places left)"
echo "Image:  $URL/Modules/Custom/SessionPlaces/image.php?c=SP26  (once published)"
