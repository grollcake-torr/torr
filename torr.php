<?PHP

/*
 * It's torr! r5
 *    공개 토렌트 사이트의 게시물을 RSS 형태로 변환
 *
 * <적용 방법>
 *    torr.php를 웹서비스 루트에 torr 디렉토리를 만들고 복사한다.
 *    (시놀로지는 웹서비스를 활성화하고 web/torr/torr.php에 넣으면 됨)
 *
 * <사용 방법>
 *   게시판은 b=xxx 형식으로 미리 정의된 것만 사용 가능하다. 게시판을 지정하지 않으면 예능,드라마,다큐,미드에서 통합 검색한다.
 *      예능: b=ent
 *      드라마: b=drama
 *      미드: b=mid
 *      다큐: b=docu
 *      기타: b=etc
 *   검색어는 k=xxx 형식으로 입력한다. 생략 시 게시판 첫 페이지를 가져온다.
 *
 * <사용예>
 *   http://your-server-ip/torr/torr.php?b=ent&k=720p+Next
 *
 *  <고급 활용>
 *   검색어는 수직바(|)로 구분하여 여러개를 입력할 수 있다. 예) http://your-server-ip/torr/torr.php?b=ent&k=썰전|아는형님|수요미식회
 *   검색어는 미리 정의한 세트명을 이용할 수도 있다. 예)  http://your-server-ip/torr/torr.php?b=ent&k=set01
 *   작동이 제대로 되지 않으면 $DEV_MODE = true로 변경하고 로그 파일(torr.log)을 살펴본다.
 *   정보를 수집할 사이트가 바뀌면 $CONFIG를 수정한다.
 *
 *  <변경 이력>
 *   20170416 - 사이트 정보 자동 업데이트 기능 추가
 *   20170425 - 자동 업데이트 오류 수정 및 첫페이지에서 마그넷 링크 검색 기능 추가
 *   20170515 - 마그넷 링크가 다른 페이지에 존재하는 경우에도 처리하도록 기능 추가
 *   20170613 - 사이트 정보 업데이트 태그(code > pre) 변경. html의 선처리 추가.
 */

########################################################################################################################
## 토렌트 사이트 정보: "$CONFIG = array( ... );" 이 부분이 자동 업데이트 된다.
########################################################################################################################
$CONFIG = array(
    "ent" => array(     # TV예능
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_ent&sca=&sop=and&sfl=wr_subject&stx={k}"
    ),
    "drama" => array(   # TV드라마
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_drama&sca=&sop=and&sfl=wr_subject&stx={k}"
    ),
    "docu" => array(    # TV다큐/시사
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_docu&sca=&sop=and&sfl=wr_subject&stx={k}"
    ),
    "mid" => array(     # 외국드라마
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_fdrama&sca=&sop=and&sfl=wr_subject&stx={k}"
    ),
    "etc" => array(     # 스포츠, 애니 등
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_sports&sca=&sop=and&sfl=wr_subject&stx={k}",
        "https://torrenthaja.com/bbs/board.php?bo_table=torrent_ani&sca=&sop=and&sfl=wr_subject&stx={k}"
    ),
    
    # 게시판을 지정하지 않은 경우 검색할 게시판 목록
    "all" => array("ent", "drama", "docu", "mid"),
    
    # 글목록, 다운로드 링크 검색을 위한 정규식 패턴. (변경자: s-단일 라인으로 처리, m-여러 라인으로 처리, i-대소문자 무시)
    "_page_link_preprocess" => array(),
    "_page_link" => '!<div class="td-subject ellipsis">.*?<a href="(?P<link>.+?)">(?P<title>.+?)</a>\s*</div>!si',
    "_down_link_preprocess" => array("/magnet_link\\('/i", "href='magnet:?xt=urn:btih:"),
    "_down_link" => 'magnet'
);

$KEYWORDS = array (
    # 검색어 Set
    "set01" => array("라디오 스타 720p", "무한도전 720p"),
    "set02" => array("썰전 720p", "아는 형님 720p", "수요미식회 720p")
);

define('DEV_MODE', false);
define('AUTO_UPDATE', true);  # 자동 업데이트 기능 사용
define('MAGNET_CACHE_CONSERVE_DAYS', 30);   # 마그넷 캐시 보존일수
define('UPDATE_URL', 'https://raw.githubusercontent.com/grollcake-torr/torr/master/README.md');  # 토렌트 사이트 정보 자동 업데이트 url

########################################################################################################################
## Common util functions
########################################################################################################################
function logger($msg) {
    date_default_timezone_set('Asia/Seoul');
    $logf = basename(__FILE__, '.php') . '.log';
    $dt = date('Y/m/d H:i:s');
    $bt = debug_backtrace();
    $func = isset($bt[1]) ? $bt[1]['function'] : '__main__';
    $caller = array_shift($bt);
    $file = basename($caller['file']);
    $output = sprintf("[%s] <%s:%s:%d> %s\n", $dt, $file, $func, $caller['line'], print_r($msg, true));
    error_log($output, 3, $logf);
}


# User-Agent와 referer를 유지하는 웹 탐색기
function curl_fetch($url, $init=false) {
    static $ch = null;
    static $cnt = 0;

    (++$cnt) % 10 == 0 && sleep(2);  # 단시간 내에 너무 많이 조회하면 차단당할 수도 있다

    $cookie_nm = './cookie.txt';

    if ($ch == null || $init == true) {
        $ch != null && curl_close($ch);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 9.0; Windows NT 6.1; Trident/5.0)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIESESSION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_nm);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    list($header, $body) = explode("\r\n\r\n", $response, 2);

    if (DEV_MODE) {
        logger("cURL fetch http_code:[$http_code] url:[$url]");
        logger($header);
        logger($body);
    }

    return array($http_code, $header, $body);
}


# 상대주소를 $base 기반의 절대주소로 반환한다.
function url_join($base, $relative_url) {
    /* return if already absolute URL */
    if (parse_url($relative_url, PHP_URL_SCHEME) != '') return $relative_url;

    /* queries and anchors */
    if ($relative_url[0]=='#' || $relative_url[0]=='?') return $base.$relative_url;

    /* parse base URL and convert to local variables: $scheme, $host, $path */
    $parsed = parse_url($base);
    $scheme = $parsed['scheme'];
    $host = $parsed['host'];
    $path = isset($parsed['path']) ? $parsed['path'] : '';

    /* remove non-directory element from path */
    $path = preg_replace('#/[^/]*$#', '', $path);

    /* destroy path if relative url points to root */
    if ($relative_url[0] == '/') $path = '';

    /* dirty absolute URL */
    $abs = "$host$path/$relative_url";

    /* replace '//' or '/./' or '/foo/../' with '/' */
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for($n=1; $n>0; $abs=preg_replace($re, '/', $abs, -1, $n)) {}

    /* absolute URL is ready! */
    return $scheme.'://'.$abs;
}


# html에서 첫번째 마그넷 링크를 찾아 반환한다.
function get_magnet_from_html($html) {
    if (preg_match('/href=[\'"]?(magnet:.+?)[\s\'">]/si', $html, $match)) {
        return $match[1];
    }
    return null;
}


# url 여부 판단
function is_url($url) {
    return isset(parse_url($url)['host']);
}


# 정규식을 이용한 변경 처리
function preprocess($text, $patterns) {
    $count = 0;
    for($i=0; $i < count($patterns); $i+=2) {
        $text = preg_replace($patterns[$i], $patterns[$i+1], $text, -1, $count);
        if ($count == 0) {
            logger('preg_replace error: ' . $patterns[$i]);
        }
    }
    return $text;
}

########################################################################################################################
## Business functions
########################################################################################################################

# 토렌트 사이트 정보 업데이트
function self_update() {
    # 업데이트 실행 여부 확인
    if (! AUTO_UPDATE) return;

    # 하루에 한번만 업데이트 체크를 해보자
    $update_check_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'torr.updatecheck';
    if ( @file_get_contents($update_check_file) == date('Ymd')) return;
    file_put_contents($update_check_file, date('Ymd'));

    # 원격 저장소에서 토렌트 사이트 정보 받아오기
    $contents = file_get_contents(UPDATE_URL);
    if (preg_match('!<pre>\s*(.+?)\s*</pre>!si', $contents, $matches)) {
        $encoded_tinfo = $matches[1];

        # 변경 여부 체크하기
        $tinfo_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'torr.siteinfo';
        $stored_tinfo = @file_get_contents($tinfo_file);
        if ($stored_tinfo == $encoded_tinfo) return;

        # base64로 인코딩 된 정보 해석하기
        $decoded_tinfo = base64_decode($encoded_tinfo);

        # 현재 스크립트 파일 백업하기
        $new_file = __FILE__ . '.' . date('Ymd');
        copy(__FILE__, $new_file);

        # 현재 스크립트 파일에 새로운 토렌트 사이트 정보 반영하기
        $cur_script = file_get_contents(__FILE__);
        $new_script = preg_replace('!^\s*\$CONFIG\s*=\s*array.+?\);!sm', $decoded_tinfo, $cur_script, 1);
        if (is_null($new_script) || $cur_script == $new_script) {
            logger("Error! Can't update torrent site info");
            return;
        }

        file_put_contents(__FILE__, $new_script);   # 변경된 내용 기록
        file_put_contents($tinfo_file, $encoded_tinfo);  # 인코딩된 토렌트 사이트 정보를 로컬에 기록해두기
        logger("Info. New torrent site info is applied successfully.");
    }
}


# 파라미터를 분석하여 탐색할 게시판 주소 목록을 생성한다.
function parse_param() {
    $conf = $GLOBALS['CONFIG'];
    $keyw = $GLOBALS['KEYWORDS'];

    $b = isset($_GET['b']) ? trim($_GET['b']) : 'all';
    if (!isset($conf[$b])) return;

    $boards = array();
    foreach ($conf[$b] as $item) {
        if (is_url($item)) {
            $boards[] = array('category'=>$b, 'url'=>$item);
        } else {
            foreach ($conf[$item] as $item2) {
                $boards[] = array('category' => $item, 'url' => $item2);
            }
        }
    }

    $k = isset($_GET['k']) ? trim($_GET['k']) : '';
    $keywords = isset($keyw[$k]) ? $keyw[$k] : explode('|', $k);
    $keywords = str_replace(' ', '%20', $keywords);

    $fetch_urls = array();
    foreach ($boards as $board) {
        foreach ($keywords as $keyword) {
            $fetch_urls[] = array('category'=>$board['category'], 'url'=>str_replace('{k}', $keyword, $board['url']));  # 검색어(키워드) 조합
        }
    }

    DEV_MODE && logger($fetch_urls);

    return $fetch_urls;
}


# 토렌트 게시판들에서 아이템(제목, 페이지링크)를 추출한다.
function get_items($board_urls) {
    $self = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . parse_url($_SERVER['REQUEST_URI'])['path'];
    $pattern = $GLOBALS['CONFIG']['_page_link'];
    $replace_patterns = $GLOBALS['CONFIG']['_page_link_preprocess'];
    $is_magnet = $GLOBALS['CONFIG']['_down_link'] == 'magnet';
    $items = array();

    foreach ($board_urls as $burl) {
        $url = $burl['url'];
        $category = $burl['category'];

        list($http_code, $header, $html) = curl_fetch($url);

        if ($http_code != 200) {
            logger("Error. Fetch from $url returned http_code $http_code");
            logger($header);
            continue;
        }

        $html = preprocess($html, $replace_patterns);  # html 내용의 일부를 미리 변경
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $title = trim(html_entity_decode(strip_tags($match['title'])), " \t\n\r\0\x0B\xC2\xA0");    # &nbsp;는 \xC2\xA0로 변환된다.
            $page = url_join($url, $match['link']);

            if ($is_magnet) {
                # 마그넷 링크까지 수집했다면 그걸 사용하고 그렇지 않으면 개별 페이지로 들어가서 마그넷 링크를 찾는다.
                $link = isset($match['magnet']) ? $match['magnet'] : get_magnet_link($page);
            } else {
                $link = $self . '?d=' . base64_encode($page);
            }
            $items[] = array('title' => $title, 'link' => $link, 'page' => $page, 'category' => $category);
        }
    }

    DEV_MODE && logger($items);

    return $items;
}


# 캐시에서 마그넷 링크를 찾아보고 없으면 url에서 찾아 반환한다.
function get_magnet_link($url) {
    $magnet = null;
    $replace_patterns = $GLOBALS['CONFIG']['_down_link_preprocess'];

    # 마그넷 캐시에서 링크를 찾아본다.
    if ($magnet = magnet_cache_control('Query', $url)) {
        return $magnet;
    }

    # 웹페이지에서 마그넷 링크를 찾는다.
    list($http_code, $header, $html) = curl_fetch($url);
    if($http_code != 200) {
        logger("Error. Fetching page from $url returned http_code $http_code");
        return null;
    }

    $html = preprocess($html, $replace_patterns);  # html 내용의 일부를 미리 변경
    $magnet = get_magnet_from_html($html);

    # 마그넷 캐시에 링크를 업데이트한다.
    $magnet && magnet_cache_control('Update', $url, $magnet);

    # 마그넷 링크를 반환한다.
    return $magnet;
}


# 마그넷 링크의 캐시를 관리한다. (조회, 추가, 저장, 오래된 데이타 삭제)
function magnet_cache_control($action, $url = null, $magnet = null) {
    static $magnet_cache = 'N/A', $cache_file = null, $cache_updated = false;
    $key = null;

    # url에서 게시물 고유번호(숫자 4자리 이상)와 캐시파일명을 찾는다.
    if ($url) {
        if (preg_match('/\d\d\d\d+/', $url, $match)) {
            $key = $match[0];
        }
        if ($cache_file == null) {
            $host = parse_url($url)['host'];
            $cache_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'torr.' . $host . '.cache';
            DEV_MODE && logger("캐시파일은 $cache_file 입니다.");
        }
    }

    # 캐시에서 마그넷 링크 조회
    if ($action == 'Query') {
        # 마그넷 캐시가 널이면 파일에서 읽어들인다.
        if ($magnet_cache == 'N/A') {
            $content = @file_get_contents($cache_file);
            if ($content != false) {
                $magnet_cache = unserialize($content);
            } else {
                $magnet_cache = array();
            }
        }

        # 마그넷 캐시에서 key 조회
        if (isset($magnet_cache[$key])) {
            $magnet = $magnet_cache[$key]['magnet'];
            DEV_MODE && logger("캐시에서 마그넷 링크를 찾았습니다. key=[$key] magnet=[$magnet]");
            return $magnet;
        }
    }
    
    # 캐시에 마그넷 링크 추가
    elseif ($action == 'Update') {
        # 마그넷 캐시가 널이면 오류 처리한다.
        if ($magnet_cache == 'N/A') {
            logger("Error. 마그넷 캐시가 생성되지 않아 Update 처리를 할 수 없습니다.");
            exit(1);
        }

        # 마그넷 캐시에 key, magnet 추가
        $magnet_cache[$key] = array('magnet' => $magnet, 'inserted' => time());
        $cache_updated = true;
    }
    
    # 캐시를 파일로 저장
    elseif ($action == 'Write') {
        if ($cache_updated) {
            # 경량 캐시 유지를 위해 일정일 이상 경과한 데이타는 삭제한다.
            $deleted = 0;
            foreach($magnet_cache as $key => $item) {
                if ($item['inserted'] + (MAGNET_CACHE_CONSERVE_DAYS*60*60*24) < time()) {
                    unset($magnet_cache[$key]);
                    $deleted++;
                }
            }
            $deleted > 0 && DEV_MODE && logger("Deleted $deleted cache items.");
            
            # 캐시 저장
            $content = serialize($magnet_cache);
            file_put_contents($cache_file, $content);
            $cache_updated = false;
        }
    }
}


# 토렌트 아이템(제목, 페이지링크)으로 rss를 생성한다.
function build_rss($items) {
    $self = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    $b = isset($_GET['b']) ? trim($_GET['b']) : '';
    $k = isset($_GET['k']) ? trim($_GET['k']) : '';
    if ($b == '' && $k == '') {
        $title = "It's torr!";
    }
    else {
        $title = "It's torr! (" . ($b ? $b . ": " : "") . ($k ? $k : "All") . ")";
    }

    $rss  = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n<rss version=\"2.0\">\n";
    $rss .= "<channel><title>$title</title><link>" . htmlentities($self) . "</link><description>A simple torrent feed by torr.</description>\n";
    foreach ($items as $item) {
        if ($item['link']) {
            $rss .= "<item><title>" . $item['title'] . "</title><link>" . htmlentities($item['link']) . "</link>";
            $rss .= "<comments>" . htmlentities($item['page']) . "</comments><category>" . $item['category'] . "</category></item>\n";
        }
    }
    $rss .= "</channel>\n</rss>";

    DEV_MODE && logger($rss);

    return $rss;
}


# 클라이언트로 rss를 전송한다.
function response($data) {
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo $data;
}


# 토렌트 rss 요청을 처리한다.
function do_rss() {
    $board_urls = parse_param();
    $torrent_items = get_items($board_urls);
    $rss = build_rss($torrent_items);
    magnet_cache_control('Write');  # 마그넷 캐시 디스크 기록
    response($rss);
}


# 토렌트 파일(.torrent)을 다운로드한다.
function download_torrent($link) {
    DEV_MODE && logger("Downloading torrent file from $link");

    list($http_code, $header, $body) = curl_fetch($link);

    if($http_code != 200) {
        logger("Error. Download torrent file from $link returned http_code $http_code");
        return;
    }

    if (preg_match('/^Content-Disposition:.+$/mi', $header, $match)) {
        header("Content-Type: application/x-bittorrent");
        header(trim($match[0]));
        echo $body;
    }
}


# 웹페이지에서 토렌트 파일 링크를 찾아 다운로드한다.
function do_download($url) {
    $replace_patterns = $GLOBALS['CONFIG']['_down_link_preprocess'];
    list($http_code, $header, $html) = curl_fetch($url);

    if ($http_code != 200) {
        logger("Error. Fetch from $url returned http_code $http_code");
        logger($header);
        return;
    }

    $html = preprocess($html, $replace_patterns);  # html 내용의 일부를 미리 변경
    $pattern = $GLOBALS['CONFIG']['_down_link'];

    if (preg_match($pattern, $html, $match)) {
        DEV_MODE && logger($match);
        $link = url_join($url, $match[1]);
        download_torrent($link);
    }
}


function main() {
    DEV_MODE && logger($_SERVER);

    if (isset($_GET['d'])) {
        do_download(base64_decode($_GET['d']));
    }
    else {
        do_rss();
        self_update();
    }
}


#################################################################
## Main()
#################################################################
main();

?>
