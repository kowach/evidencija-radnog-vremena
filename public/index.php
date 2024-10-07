<?php
/**
 * Evidencija radnog vremena
 *
 * @author    Vladimir Kovačević
 * @created   23-Feb-2016
 * @link      https://github.com/kowach/evidencija-radnog-vremena
 * @licence   MIT License
 *
 */
use ICal\ICal;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

error_reporting(E_ALL);
date_default_timezone_set( 'UTC' );
mb_internal_encoding( 'UTF-8' );
if(getenv('APPLICATION_ENV')=='development') {
    ini_set('display_errors',E_ALL);
}

require __DIR__ . '/../vendor/autoload.php';

# configuration
$year = date('Y');
$calendarUrl = 'https://www.kayaposoft.com/enrico/ics/v2.0?country=hrv&fromDate=01-01-'.($year-1).'&toDate=31-12-'.($year+2).'&region=&holidayType=public_holiday&lang=hr';

$locale = 'hr_HR';
$baseUrl = dirname( $_SERVER['PHP_SELF'] );
setlocale(LC_ALL, $locale.'.UTF-8');
$calendarCacheFile = __DIR__ . '/../cache/i_calendar_cache_file_v2.ics';
$xlsTemplateFile = __DIR__ . '/assets/evidencija-randog-vremena-v2.xlsx';


$notWorkingReasons = [
    'N'=>'Godišnji odmor',
    'O'=>'Bolovanje',
    'P'=>'Plaćenoi dopust',
    'Q'=>'Očinski dopust',
    'J'=>'Terenskog rad',
];

/**
 * Filtrira post varijablu
 *
 * @param $key
 *
 * @return string
 */
function getPostVal($key)
{
    if(!isset($_POST[$key])) return '';

    $val = trim(strip_tags($_POST[$key]));
    if(mb_strlen($val)>250) $val=mb_substr($val,0,250);
    return $val;
}

if($_SERVER['REQUEST_METHOD']==='POST') {

    # IntlDateFormatter needs php extension intl
    # http://userguide.icu-project.org/formatparse/datetime
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::FULL);


    if(!file_exists($calendarCacheFile) || filectime($calendarCacheFile)+86400*60 < time()) {
        $cacheDir = dirname($calendarCacheFile);
        if(!file_exists($cacheDir) ) {
            if ( ! mkdir( $cacheDir, 0777 ) ) {
                die( "ERROR: Cannot create cache dir: $cacheDir" );
            }
        }
        if(!file_put_contents($calendarCacheFile, file_get_contents($calendarUrl))) {
            die("ERROR: Cannot write cache iCalendar cache file");
        }
    }
    $xlsReader = IOFactory::createReader('Xlsx');
    $spreadsheet = $xlsReader->load($xlsTemplateFile);
    $sheet = $spreadsheet->getActiveSheet();


    $trenutni_mjesec = isset($_POST['trenutni_mjesec'])&&$_POST['trenutni_mjesec']==1;

    $notWorkingReason = '';
    if(isset($_POST['notWorkingReason'],$notWorkingReasons[$_POST['notWorkingReason']])) {
        $notWorkingReason = $_POST['notWorkingReason'];
    }

    $godisnji_od = (int) (isset($_POST['godisnji_od'])?$_POST['godisnji_od']:0);
    $godisnji_do = (int) (isset($_POST['godisnji_do'])?$_POST['godisnji_do']:0);

    $mjesec = (int) (isset($_POST['mjesec'])?$_POST['mjesec']:date('m'));
    $godina = (int) (isset($_POST['godina'])?$_POST['godina']:date('Y'));
    if($trenutni_mjesec && $mjesec!=date('m')) $trenutni_mjesec=false;

    $blagdan = isset($_POST['blagdan'])&&$_POST['blagdan']==1;
    $subota = (int)@$_POST['subota']; // sati

    $mjesec_dana = cal_days_in_month (CAL_GREGORIAN, $mjesec, $godina);

    if($godisnji_od>0) {
        if($godisnji_do<1 || $godisnji_od > $godisnji_do || $godisnji_do>$mjesec_dana ) $godisnji_do=$mjesec_dana;
    }
    if($godisnji_do>0) {
        if($godisnji_od<1 || $godisnji_od > $godisnji_do || $godisnji_od>$mjesec_dana ) $godisnji_od=1;
    }


    $sheet->setCellValue('D2', getPostVal('zaposlenik_naziv') );
    $date = new DateTime();

    $curDay = (int)$date->format('d');

    $date->setDate($godina,$mjesec,1);



    $ical = new ICal($calendarCacheFile);
    $events = $ical->events();

    // praznici u trenutnom mjesecu
    $praznici=[];
    foreach($events as $event) {
        $praznik = new DateTime( '@'. $ical->iCalDateToUnixTimestamp($event->dtstart));

        if($date->format('Y')==$praznik->format('Y') && $date->format('m')==$praznik->format('m'))
        {
            $praznici[]=(int)$praznik->format('j');
        }
    }

    $formatter->setPattern('LLLL');
    $sheet->setCellValue('D3', mb_strtoupper( $formatter->format($date) ) );
    $sheet->setCellValue('D4', $date->format('Y') );

    $formatter->setPattern('yyyy LLLL');
    $fileName =  $formatter->format($date)." - ".getPostVal('zaposlenik_naziv');

    $ukupnoSati = 0;
    $headingRow = 8;
    $formatter->setPattern('EEE');
    for($i=1;$i<=$mjesec_dana;$i++) {
        $row = $headingRow+$i;
        $sheet->setCellValue( 'A'.$row, $date->format('j').'.' );

        $w = $date->format('w');

        # ako je odabran do danas za trenutni mjesec && nije nedjelja && odabrana subota
        if( (!$trenutni_mjesec || $i <= $curDay) && $w != 0 && ($w != 6 || $subota>0))
        {
            $pocetak = (int)@$_POST['pocetak'];
            if($w == 6 && $subota>0) {
                $kraj = (int)@$_POST['pocetak'] + $subota;
                $satnica = $subota;
            }
            else {
                $kraj = (int)@$_POST['kraj'];
                $satnica = (int)@$_POST['satnica'];
            }

            $sheet->setCellValue( 'B'.$row, $pocetak.':00' );
            $sheet->setCellValue( 'C'.$row, $kraj.':00' );
            $sheet->setCellValue( 'D'.$row, $satnica );

            $col = '';

            # godišnji
            if($i>=$godisnji_od && $i<=$godisnji_do) {
                $col = $notWorkingReason; // Vrijeme korištenja neradnog vremena
            }

            # praznici
            if( in_array($i, $praznici, true)  ) {
                $col = 'G';
            }
            if($col!=='') {
                $sheet->setCellValue($col . $row, $satnica);
            }




            $ukupnoSati+=$satnica;
        }
        else {
            $sheet->setCellValue( 'B'.$row, mb_strtoupper($formatter->format($date)) );
        }
        $date->add(new DateInterval('P1D'));

    }

    # Fix LibreOffice warning: The data could not be loaded completely because the maximum number of columns per sheet was exceeded.
    $maxColumnIndex = 100;

    $columnDimensions = $sheet->getColumnDimensions();
    foreach ($columnDimensions as $col => $dimension) {
        if (PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col) > $maxColumnIndex) {
            unset($columnDimensions[$col]);
        }
    }

    $reflector = new ReflectionClass($sheet);
    $property = $reflector->getProperty('columnDimensions');
    $property->setAccessible(true);
    $property->setValue($sheet, $columnDimensions);



    # Render
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="'.$fileName.'.xlsx"');
    header('Cache-Control: max-age=0');

    $excelWriter = new Xlsx($spreadsheet);
    $excelWriter->setPreCalculateFormulas(true);
    $excelWriter->save('php://output');

    exit;
}

?>
<html xmlns:og="http://opengraphprotocol.org/schema/">
<head>
    <title>Evidencija radnog vremena - Web aplikacija</title>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Evidencija radnog vremena - Web aplikacija za izradu tablice evidencije radnog vremena"/>
    <meta property="og:title" content="Evidencija radnog vremena - Web aplikacija"/>
    <meta property="og:image" content="<?php echo $baseUrl; ?>assets/evidencija%20radnog%20vremena%20-%20web_app.png"/>
    <meta property="og:url" content="<?php echo $baseUrl; ?>" />
    <link rel="stylesheet" href="assets/bootstrap.min.css">
    <script src="assets/jquery.min.js"></script>
    <style>
        .jumbotron{
            padding: 1rem 2rem;
        }
        .form-group {
            margin-right: 15px;
        }
    </style>
    <script type="application/javascript">

        $( document ).ready(function() {

            $('#dodajPoslodavca').click(function(){
                var poslodavac =  JSON.parse(localStorage.getItem('evidencija.poslodavac'));
                if(poslodavac==null) {
                    poslodavac=[];
                }
                var obj={naziv:$('#poslodavac1').val(), oib:$('#oib1').val()};
                if(obj.naziv=='' || obj.oib=='') {
                    alert("Naziv i OIB moraju biti popunjeni");
                    return false;
                }
                poslodavac.push(obj);
                localStorage.setItem('evidencija.poslodavac', JSON.stringify(poslodavac));

                fillData();

                $('#poslodavac').val(poslodavac.length-1);

            });
            $('#dodajZaposlenika').click(function(){
                var zaposlenik =  JSON.parse(localStorage.getItem('evidencija.zaposlenik'));
                if(zaposlenik==null) {
                    zaposlenik=[];
                }
                var obj={naziv:$('#zaposlenik2').val(), oib:$('#oib2').val(), adresa:$('#adresa2').val()};
                if(obj.naziv=='' || obj.oib=='') {
                    alert("Naziv, adresa i OIB moraju biti popunjeni");
                    return false;
                }
                zaposlenik.push(obj);
                localStorage.setItem('evidencija.zaposlenik', JSON.stringify(zaposlenik));

                fillData();

                $('#zaposlenik').val(zaposlenik.length-1);
            });

            fillData();

            for(var i=1;i<=31;i++) {
                $('#godisnji_od')
                    .append($('<option>', { value : i })
                        .text(i));
                $('#godisnji_do')
                    .append($('<option>', { value : i })
                        .text(i));
            }
            for(i=1;i<=12;i++) {
                let option = $('<option>', { value : i }).text(i);
                if(new Date().getMonth()==i) option.prop('selected',true);
                $('#mjesec').append(option);
            }
            for(var g=2014;g<=2025;g++) {
                $('#godina')
                    .append($('<option>', { value : g })
                        .text(g));
            }
            $('#godina').val(new Date().getFullYear());
        });

        function fillData()
        {
            $('#poslodavac').html('');
            var poslodavac =  JSON.parse(localStorage.getItem('evidencija.poslodavac'));
            $.each(poslodavac,function(k,item){
                $('#poslodavac')
                    .append($('<option>', { value : k })
                        .text(item.naziv));
            });
            $('#zaposlenik').html('');
            var zaposlenik =  JSON.parse(localStorage.getItem('evidencija.zaposlenik'));
            $.each(zaposlenik,function(k,item){
                $('#zaposlenik')
                    .append($('<option>', { value : k })
                        .text(item.naziv+', '+item.oib+', '+item.adresa));
            });
        }
        function setData(key,val)
        {
            var f=$('#form-post');
            if($('#'+key).length==0) {
                f.append('<input type="hidden" name="'+key+'" id="'+key+'">');
            }
            $('#'+key).val(val);
        }

        function removePoslodavac()
        {
            var poslodavac =  JSON.parse(localStorage.getItem('evidencija.poslodavac'));
            poslodavac.splice(parseInt($('#poslodavac').val(),10),1);
            localStorage.setItem('evidencija.poslodavac', JSON.stringify(poslodavac));
            fillData();
        }
        function removeZaposlenik()
        {
            var zaposlenik =  JSON.parse(localStorage.getItem('evidencija.zaposlenik'));
            zaposlenik.splice(parseInt($('#zaposlenik').val(),10),1);
            localStorage.setItem('evidencija.zaposlenik', JSON.stringify(zaposlenik));
            fillData();
        }

        function submitData()
        {
            var zaposlenik =  JSON.parse(localStorage.getItem('evidencija.zaposlenik'))[$('#zaposlenik').val()];
            var poslodavac =  JSON.parse(localStorage.getItem('evidencija.poslodavac'))[$('#poslodavac').val()];
            setData('poslodavac_naziv',poslodavac.naziv);
            setData('poslodavac_oib',poslodavac.oib);
            setData('zaposlenik_naziv',zaposlenik.naziv);
            setData('zaposlenik_oib',zaposlenik.oib);
            setData('zaposlenik_adresa',zaposlenik.adresa);
            if(typeof 'ga' != "undefined")
                ga('send', 'pageview', '/bannerads/EvidencijaRadnogVremena/'+$('#godina').val()+'-'+$('#mjesec').val());
            return true;
        }

    </script>

</head>
<body>
<div class="jumbotron">
    <div class="container">
        <h1>Evidencija radnog vremena</h1>
        <p>Besplatna web aplikacija za izradu tablice evidencije radnog vremena koju je moguće naknadno urediti. <br/>
            UPUTE: Poslodavci i zaposlenici se spremaju u memoriju browsera i bit će dostupni samo na istom računalu i browseru.<br/>
            Klikom na download se skida XLSX tablica s popunjenim poljima za odabrani mjesec (<a target="_blank" href="<?php echo $baseUrl; ?>assets/evidencija radnog vremena - screen_xls.png">screen shot</a>).

        </p>
        <p><a target="_blank" href="https://metaprofile.tv/contact-us/">&copy; Metaprofile Data d.o.o.</a></p>
    </div>
</div>
<div class="container">


    <div class="jumbotron">
        <form class="form-inline" onsubmit="return false">
            <div class="form-row pb-1">
                <div class="form-group">
                    <label for="poslodavac1">Poslodavac:</label>
                    <input type="text" class="form-control" id="poslodavac1" placeholder="poslodavac d.o.o.">
                </div>
                <div class="form-group">
                    <label for="oib1">OIB:</label>
                    <input type="text" class="form-control" id="oib1" placeholder="OIB">
                </div>
                <button type="button" class="btn btn-light" id="dodajPoslodavca">Dodaj</button>
            </div>
            <hr/>

            <div class="form-row">
                <div class="form-group">
                    <label for="zaposlenik2">Zaposlenik:</label>
                    <input type="text" class="form-control" id="zaposlenik2" placeholder="ime prezime">
                </div>
                <div class="form-group">
                    <label for="oib2">OIB:</label>
                    <input type="text" class="form-control" id="oib2" placeholder="OIB">
                </div>
                <div class="form-group">
                    <label for="oib2">Adresa:</label>
                    <input type="text" class="form-control" id="adresa2" placeholder="adresa broj, grad">
                </div>
                <button type="button" class="btn btn-light" id="dodajZaposlenika">Dodaj</button>
            </div>
        </form>
    </div>
    <div class="jumbotron">
        <form class="form-inline" method="post" id="form-post" action="index.php" onsubmit="return submitData()">
            <div class="form-row pb-2">
                <div class="form-group">
                    <label for="pocetak">Početak i kraj rada:</label>
                    <input class="form-control" name="pocetak" id="pocetak" size="2" value="8">
                    <input class="form-control" name="kraj" id="kraj" size="2" value="16">
                </div>
                <div class="form-group">
                    <label for="satnica">Dnevna satnica:</label>
                    <input class="form-control" name="satnica" id="satnica" size="2" value="8">
                </div>
                <div class="form-group">
                    <label for="subota">Rad subotom (sati):</label>
                    <input class="form-control" name="subota" id="subota" size="2" value="">
                </div>
                <div class="form-group">
                    <label for="blagdan">Rad blagdanima</label>
                    <input class="form-control" type="checkbox" name="blagdan" id="blagdan" value="1">
                </div>
            </div>
            <div class="form-row pb-2">
                <div class="form-group">
                    <label for="poslodavac">Poslodavac</label>
                    <select class="form-control" name="poslodavac" id="poslodavac" style="max-width: 300px"></select>
                    <button type="button" class="form-control btn-danger btn-sm" onclick="removePoslodavac()" title="Obriši poslodavca">×</button>
                </div>
                <div class="form-group">
                    <label for="zaposlenik">Zaposlenik</label>
                    <select class="form-control" name="zaposlenik" id="zaposlenik" style="max-width: 300px"></select>
                    <button type="button" class="form-control btn-danger btn-sm" onclick="removeZaposlenik()" title="Obriši zaposlenika">×</button>
                </div>
            </div>

            <div class="form-row pb-2">
                <div class="form-group">
                    <label for="mjesec">Mjesec</label>
                    <select class="form-control" name="mjesec" id="mjesec"></select>
                </div>
                <div class="form-group">
                    <label for="godina">Godina</label>
                    <select class="form-control" name="godina" id="godina"></select>
                </div>
                <div class="form-group">
                    <label for="trenutni_mjesec">Za trenutni mjesec ispuni samo do danas</label>
                    <input class="form-control" type="checkbox" name="trenutni_mjesec" id="trenutni_mjesec" value="1">
                </div>
                <div class="form-group">
                    <label for="godisnji_od"><select name="notWorkingReason" class="form-control" style="width: 200px;">
                            <?php
                            foreach ($notWorkingReasons as $i=>$reason) echo '<option value="'.$i.'">'.htmlspecialchars($reason).'</option>';
                            ?>
                        </select> od</label>
                    <select class="form-control" name="godisnji_od" id="godisnji_od"><option></option></select>
                    do
                    <select class="form-control" name="godisnji_do" id="godisnji_do"><option></option></select>
                </div>
            </div>
            <div class="form-row pb-2">
                <div class="form-group">
                    <button type="submit" class="form-control btn btn-success">Download</button>
                </div>
            </div>


        </form>

        <div class="row">
            <div class="col">
                <div class="text-right">
                    Kontakt: <a target="_blank" href="mailto:tvprofil@tvprofil.net?subject=Evidencija+radnog+vremena">tvprofil@tvprofil.net</a>. Posjetite naš regionalni TV portal: <a target="_blank" title="TV program" href="https://tvprofil.com">https://tvprofil.com</a>
                </div>
            </div>
        </div>
    </div>

    <div>Oglasi:</div>

    <script async src="//pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
    <!-- Wide970 -->
    <ins class="adsbygoogle"
         style="display:inline-block;width:970px;height:250px"
         data-ad-client="ca-pub-9126465967402353"
         data-ad-slot="6462899684"></ins>
    <script>
        (adsbygoogle = window.adsbygoogle || []).push({});
    </script>
</div>


<script type="text/javascript">
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
            (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
        m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

    ga('create', 'UA-2557323-1', 'auto');
    ga('send', 'pageview');
</script>

</body>
</html>

