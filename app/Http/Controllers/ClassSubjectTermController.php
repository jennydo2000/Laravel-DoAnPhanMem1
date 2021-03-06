<?php
namespace App\Http\Controllers;

use App\Models\Class_;
use App\Models\ClassSubject;
use App\Models\ClassSubjectStudent;
use App\Models\ClassSubjectTerm;
use App\Models\Term;
use TCPDF;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDF;
use TCPDF_FONTS;

class ClassSubjectTermController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, $term_id)
    {
        $this->authorizeForUser(Auth::user(), 'viewAny', ClassSubjectTerm::class);

        $selection = [
            'class_subject_terms.id as id',
            'class_subjects.id as class_subject_id',
            'subjects.name as subject_name',
            'teacher_id',
            'users.first_name as teacher_first_name',
            'users.last_name as teacher_last_name',
            DB::raw('concat(users.first_name, " " , users.last_name) as teacher_full_name'),
            DB::raw('(select count(*) from class_subject_students where class_subject_students.class_subject_id = class_subjects.id) as student_number'),
            'registration_start',
            'registration_end',
            'credit_number',
            'subjects.code as code',
        ];

        $searching = [
            'keyword' => $request->keyword,
            'columns' => [
                'subjects.name', 
                'users.first_name',
                'users.last_name',
            ],
        ];

        $filters = [
		    
        ];

        $description = [
            'class_subject_number' => ClassSubjectTerm::where('term_id', $term_id)->count(),
        ];

        $data = ClassSubject::join('users', 'class_subjects.teacher_id', 'users.id')
            ->join('subjects', 'class_subjects.subject_id', 'subjects.id')
            ->join('class_subject_terms', 'class_subjects.id', 'class_subject_terms.class_subject_id')
            ->join('terms', 'terms.id', 'class_subject_terms.term_id')
            ->where('class_subject_terms.term_id' , $term_id);
        $data = $this->indexDB($data, $selection, $searching, $filters);

        return response()->json(['dataSource' => $data->paginate(10), 'description' => $description]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($term_id)
    {
        $this->authorizeForUser(Auth::user(), 'create', ClassSubjectTerm::class);

        $classSubjects = ClassSubject::join('subjects', 'class_subjects.subject_id', 'subjects.id')
            ->join('classes', 'class_subjects.class_id', 'classes.id')
            ->join('majors', 'majors.id', 'classes.major_id')
            ->join('grades', 'grades.id', 'classes.grade_id')
            ->whereRaw('class_subjects.id not in (select class_subject_id from class_subject_terms where term_id = ?)', $term_id)
            ->select('class_subjects.id as id', DB::raw('concat(subjects.name, " - " , concat("K", grades.name, majors.short_name)) as name'))
            ->get();
        return response()->json(['class_subjects' => $classSubjects]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $term_id)
    {
        $this->authorizeForUser(Auth::user(), 'create', ClassSubjectTerm::class);

        try {
            $next = false;
            foreach($request->class_subject_id as $id) {
                //Sinh vi??n ???? t???n t???i trong l???p
                if (ClassSubjectTerm::where('class_subject_id', $id)->where('term_id', $term_id)->count())
                    continue;
                $this->insertDB('class_subject_terms', [
                    'class_subject_id' => $id,
                    'term_id' => $term_id,
                ], true, $next);
                $next = true;
            }
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
        return response()->json(['type' => 'success', 'text' => '???? th??m l???p h???c ph???n v??o h???c k??']);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $this->authorizeForUser(Auth::user(), 'view', [ClassSubjectTerm::class, $id]);
        
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $this->authorizeForUser(Auth::user(), 'update', ClassSubjectTerm::class);

        $data = ClassSubject::find($id);
        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser(Auth::user(), 'update', ClassSubjectTerm::class);
        
        return response()->json(['type' => 'success']);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $this->authorizeForUser(Auth::user(), 'delete', ClassSubjectTerm::class);

        try {
            $validate =  $this->deleteDB('class_subject_terms', $request->ids, $request->deleteRelation == 1 ? true : false);
            if ($validate === null)
                return response()->json(['type' => 'success', 'text' => '???? x??a l???p h???c ph???n ra kh???i h???c k??']);
            else
                return response()->json(['type' => 'error', 'text' => 'X??a l???p h???c ph???n ra kh???i l???p sinh ho???t kh??ng th??nh c??ng. ' . $validate]);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    public function registerList($term_id) {
        $this->authorizeForUser(Auth::user(), 'register', ClassSubjectTerm::class);
        
        $data = ClassSubjectStudent::query()->where('student_id', Auth::user()->id)->whereRaw('class_subject_id in (select class_subject_id from class_subject_terms where term_id = ?)', $term_id)->get()->pluck('class_subject_id');
        return response()->json($data);
    }

    public function register(Request $request, $term_id) {
        $this->authorizeForUser(Auth::user(), 'register', ClassSubjectTerm::class);
        try {
            //Ki???m tra cho ph??p ????ng k??
            if (!TermController::get()->where('id', $term_id)->count())
                return response()->json(['type' => 'error', 'text' => 'H???c k?? n??y ???? b??? kh??a ????ng k?? t??n ch??? ho???c l?? ch??a t???i th???i ??i???m ????ng k?? ho???c ???? h???t th???i gian ????ng k??']);
            $list = $request->list;
            $data = ClassSubjectStudent::query()->where('student_id', Auth::user()->id)->whereRaw('class_subject_id in (select class_subject_id from class_subject_terms where term_id = ?)', $term_id)->get();
            $addList = [];
            $delList = [];
            $now = Carbon::now()->toDateTimeString();
            foreach($list as $item) {
                if (!$data->where('class_subject_id', $item)->count()) {
                    array_push($addList, ['student_id' => Auth::user()->id, 'class_subject_id' => $item, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            foreach($data as $item) {
                if (!in_array($item->class_subject_id, $list)) {
                    array_push($delList, $item->id);
                }
            }
            ClassSubjectStudent::destroy($delList);
            ClassSubjectStudent::insert($addList);
            return response()->json(['type' => 'success', 'text' => '???? c???p nh???t ????ng k?? t??n ch??? th??nh c??ng']);
        } catch (Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    public function print(Request $request, $term_id) {
        $this->authorizeForUser(Auth::user(), 'register', ClassSubjectTerm::class);
        
        try {
            //Config
            $font = 'freeserif';

            //Get data
            $data = ClassSubject::join('users', 'class_subjects.teacher_id', 'users.id')
                ->join('subjects', 'class_subjects.subject_id', 'subjects.id')
                ->join('class_subject_terms', 'class_subjects.id', 'class_subject_terms.class_subject_id')
                ->join('terms', 'terms.id', 'class_subject_terms.term_id')
                ->join('class_subject_students', 'class_subject_students.class_subject_id', 'class_subjects.id')
                ->where('class_subject_terms.term_id' , $term_id)
                ->where('class_subject_students.student_id', Auth::user()->id)
                ->select('subjects.code as code', 'subjects.name as name', 'subjects.credit_number as credit_number')
                ->get();

            $id = Auth::user()->name;
            $name = Auth::user()->first_name . ' ' . Auth::user()->last_name;
            $class = Class_::join('majors', 'classes.major_id', 'majors.id')
                ->join('grades', 'classes.grade_id', 'grades.id')
                ->select(DB::raw('concat("K", grades.name, majors.short_name) as class_name'))   
                ->where( 'classes.id', Auth::user()->class_id)->first()->class_name;
            $termData = Term::where('id', $term_id)->first();
            $term = '1';
            $termYear = $termData->starting_year . '-' . $termData->end_year;
            $phone = Auth::user()->phone;
            $time = Carbon::now();
            $date = $time->day;
            $month = $time->month;
            $year = $time->year;

            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
            
            $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);

            $pdf->AddPage();

            $pdf->SetFont($font, '', 12, '', true);
            $html = <<<HTML
                <style>
                    :root {
                        font-size: 12;
                    }
                    .header {
                        text-align: center;
                        font-size: 13;
                    }
                    .sign {
                        
                    }
                    .center {
                        text-align: center;
                    }
                    .table {
                        border: 1px solid black;
                        border-collapse: collapse;
                        vertical-align: middle;
                        border-spacing: 10px;
                    }
                    .tableHeader {
                        text-align: center;
                    }
                    .tableContent {
                        border-bottom: 1px dotted black;
                    }
                </style>
                <table>
                    <tr>
                        <td class="header" width="40%">PH??N HI???U ??H??N T???I KON TUM</td>
                        <td class="header" width="60%"><b>C???NG H??A X?? H???I CH??? NGH??A VI???T NAM</b></td>
                    </tr>
                    <tr>
                        <td class="header"><b>PH??NG ????O T???O</b></td>
                        <td class="header"><b>?????c l???p - T??? do - H???nh ph??c</b></td>
                    </tr>
                    <tr>
                        <td class="header">---------------</td>
                        <td class="header">----------o0o----------</td>
                    </tr>
                </table>
                <p style="text-align: center; font-size: 15"><b>PHI???U ????NG K?? T??N CH???</b></p>
                <p>H??? v?? t??n sinh vi??n: $name &emsp;&emsp;&emsp; MSSV: $id &emsp;&emsp;&emsp; L???p: $class</p>
                <p>H???c k??: $term &emsp;&emsp;&emsp; N??m h???c: $termYear &emsp;&emsp;&emsp; S??? ??i???n tho???i: $phone</p>
                <p>C??c h???c ph???n ????ng k??: </p>
                <table cellpadding="5">
                    <tr>
                        <th class="table tableHeader" width="5%">S??? TT</th>
                        <th class="table tableHeader" width="25%">M?? l???p h???c ph???n</th>
                        <th class="table tableHeader" width="40%">T??n h???c ph???n</th>
                        <th class="table tableHeader" width="10%">S??? TC</th>
                        <th class="table tableHeader" width="10%">L???n h???c</th>
                        <th class="table tableHeader" width="10%">Ghi ch??</th>
                    </tr>
            HTML;
            $counter = 0;
            $creditCounter = 0;
            foreach($data as $item) {
                $itemCode = $item->code;
                $itemName = $item->name;
                $itemCreditNumber = $item->credit_number;
                $itemStudyingTime = 1;
                $itemNote = '';

                $counter++;
                $creditCounter += intval($itemCreditNumber);

                $html .= <<<HTML
                    <tr>
                        <td class="table tableContent center">$counter</td>
                        <td class="table tableContent">$itemCode</td>
                        <td class="table tableContent">$itemName</td>
                        <td class="table tableContent center">$itemCreditNumber</td>
                        <td class="table tableContent center">$itemStudyingTime</td>
                        <td class="table tableContent">$itemNote</td>
                    </tr>
                HTML;
            }

            $html .= <<<HTML
                </table>
                <p><b>T???ng s??? HP ????ng k??: $counter S??? TC: $creditCounter</b></p>
                <p style="text-align: right"><i>Kon Tum, ng??y $date th??ng $month n??m $year</i></p>
                <table class="sign">
                    <tr>
                        <td class="center"><b>C??? v???n h???c t???p</b></td>
                        <td class="center"><b>Sinh vi??n ????ng k??</b></td>
                    </tr>
                    <tr>
                        <td class="center">(k?? v?? ghi r?? h??? t??n)</td>
                        <td class="center">(k?? v?? ghi r?? h??? t??n)</td>
                    </tr>
                </table>
                <br/>
                <br/>
                <br/>
                <br/>
                <br/>
                <p style="font-size: 11"><b><u>Ghi ch??:</u></b></p>
                <p style="font-size: 10">- C??c khi???u n???i v??? k???t qu??? ????ng k?? h???c ph???n v???i l?? do nh??? ng?????i kh??c ????ng k??, ghi sai t??n h???c ph???n, l???p HP ????ng k?? h???c, n???p kh??ng ????ng th???i gian quy ?????nh ?????u kh??ng ???????c gi???i quy???t.</p>
                <p style="font-size: 10">- Sinh vi??n ????ng k?? h???c v???i l???p h???c ph???n n??o, ph???i theo d??i l???ch h???c c???a l???p h???c ph???n ???? sau khi ???????c x??t duy???t ????? tham gia h???c ????ng th???i gian quy ?????nh.</p>
            HTML;

            $pdf->writeHTML($html, true, false, true, false, '');

            $pdf->Output('DangKyTinChi.pdf', 'I');
        } catch (Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }
}
