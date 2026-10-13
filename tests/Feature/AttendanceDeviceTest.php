<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use App\Support\AttendancePunches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class AttendanceDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, config('app.timezone'));
    }

    public function test_punches_mark_present_with_first_check_in_and_keep_hand_marks(): void
    {
        $ram = Employee::factory()->create(['device_user_id' => '7']);
        $sita = Employee::factory()->create(['device_user_id' => '8']);
        $sita->attendances()->create(['date' => '2026-10-13', 'status' => 'leave', 'note' => 'Sick', 'source' => 'manual']);

        $result = AttendancePunches::apply([
            ['user' => '0007', 'time' => $this->at('2026-10-13 09:40')],
            ['user' => '7', 'time' => $this->at('2026-10-13 09:05')],
            ['user' => '7', 'time' => $this->at('2026-10-13 18:02')],
            ['user' => '8', 'time' => $this->at('2026-10-13 10:00')],
            ['user' => '99', 'time' => $this->at('2026-10-13 10:00')],
        ]);

        $this->assertSame(['marked' => 1, 'kept' => 1, 'punches' => 5, 'unknown' => ['99']], $result);
        $mark = $ram->attendances()->sole();
        $this->assertSame(['present', '09:05', '18:02', 'device'], [$mark->status, $mark->check_in, $mark->check_out, $mark->source]);
        $this->assertSame(['9:05 AM', '6:02 PM', '8h 57m'], [\App\Models\Attendance::clock($mark->check_in), \App\Models\Attendance::clock($mark->check_out), $mark->worked()]);
        $this->assertSame('leave', $sita->attendances()->sole()->status);

        // A later sync with a midday punch keeps the earliest check-in and latest check-out.
        AttendancePunches::apply([['user' => '7', 'time' => $this->at('2026-10-13 12:00')]]);
        $this->assertSame(['09:05', '18:02'], [$mark->fresh()->check_in, $mark->fresh()->check_out]);

        // Two quick punches on arrival are not a check-out.
        AttendancePunches::apply([['user' => '7', 'time' => $this->at('2026-10-12 09:05')], ['user' => '7', 'time' => $this->at('2026-10-12 09:20')]]);
        $this->assertNull($ram->attendances()->whereDate('date', '2026-10-12')->sole()->check_out);
    }

    public function test_sync_reads_events_from_the_device_page_by_page(): void
    {
        $this->travelTo($this->at('2026-10-14 11:00'));
        $emp = Employee::factory()->create(['device_user_id' => '12']);
        Setting::put(['device_host' => '192.168.1.64', 'device_username' => 'admin', 'device_password' => Crypt::encryptString('secret')]);

        $pages = [
            ['AcsEvent' => ['responseStatusStrg' => 'MORE', 'InfoList' => [
                ['major' => 5, 'minor' => 38, 'time' => '2026-10-14T09:12:44+05:45', 'employeeNoString' => '12'],
                ['major' => 2, 'minor' => 1024, 'time' => '2026-10-14T09:00:00+05:45'], // door event, no person
            ]]],
            ['AcsEvent' => ['responseStatusStrg' => 'OK', 'InfoList' => [
                // Device left on GMT+8: the clock time it shows is still Nepal time.
                ['major' => 5, 'minor' => 38, 'time' => '2026-10-13T08:58:10+08:00', 'employeeNoString' => '12'],
                ['major' => 5, 'minor' => 38, 'time' => '2026-10-11T09:00:00+05:45', 'employeeNoString' => '12'], // outside the 2 days asked for
            ]]],
        ];

        // Like the real device: ask for a digest login first, then answer with the next page.
        Http::fake(['192.168.1.64/ISAPI/AccessControl/AcsEvent*' => function (Request $request) use (&$pages) {
            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="DS-K1A8603EF-B", qop="auth", nonce="abc123", opaque="xyz"']);
            }

            return Http::response(array_shift($pages));
        }]);

        $result = AttendancePunches::syncFromDevice(2);

        $this->assertSame(2, $result['marked']);
        $this->assertSame(['2026-10-13' => '08:58', '2026-10-14' => '09:12'], $emp->attendances()->orderBy('date')->get()->mapWithKeys(fn ($a) => [$a->date->toDateString() => $a->check_in])->all());
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://192.168.1.64/ISAPI/AccessControl/AcsEvent') && $r['AcsEventCond']['searchResultPosition'] === 2);
        Http::assertSent(fn (Request $r) => isset($r['AcsEventCond']) && strlen($r['AcsEventCond']['searchID']) <= 20);
        $this->assertNotEmpty(Setting::get('device_last_sync'));
        $this->assertSame('', Setting::get('device_last_error'));
    }

    public function test_settings_page_saves_the_device_tests_it_and_links_employees(): void
    {
        $this->actingAs(User::factory()->create());
        $ram = Employee::factory()->create(['name' => 'Ram Thapa', 'status' => 'active']);
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'status' => 'active']);

        $this->get(route('settings.attendance-device'))->assertOk()->assertSee(['Connect the device', 'Ram Thapa']);

        Http::fake(['10.0.0.5/ISAPI/System/deviceInfo' => Http::response('<DeviceInfo><deviceName>Office</deviceName><model>DS-K1A8603EF-B</model><serialNumber>ABC123</serialNumber></DeviceInfo>')]);

        $page = Livewire::test('pages::settings.attendance-device')
            ->set('host', 'not a host!')
            ->call('saveConnection')
            ->assertHasErrors(['host', 'password'])
            ->set('host', '10.0.0.5')
            ->set('password', 'secret')
            ->set('autoSync', true)
            ->call('saveConnection')
            ->assertHasNoErrors()
            ->call('testConnection')
            ->assertSet('test.ok', true)
            ->assertSee('Connected to DS-K1A8603EF-B');

        $this->assertSame('secret', Crypt::decryptString(Setting::get('device_password')));
        $this->assertSame('1', Setting::get('device_auto_sync'));

        // Saving again with a blank password keeps the saved one.
        $page->set('password', '')->call('saveConnection')->assertHasNoErrors();
        $this->assertSame('secret', Crypt::decryptString(Setting::get('device_password')));

        $page->set("ids.{$ram->id}", '7')->set("ids.{$sita->id}", '007')->call('saveIds')->assertHasErrors();
        $page->set("ids.{$sita->id}", '8')->call('saveIds')->assertHasNoErrors();
        $this->assertSame(['7', '8'], [$ram->fresh()->device_user_id, $sita->fresh()->device_user_id]);
    }

    public function test_wrong_password_is_explained(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response('', 401)]);

        Livewire::test('pages::settings.attendance-device')
            ->set('host', '10.0.0.5')->set('password', 'nope')
            ->call('testConnection')
            ->assertSet('test.ok', false)
            ->assertSee('username or password is wrong');
    }

    public function test_csv_export_can_be_imported(): void
    {
        $this->actingAs(User::factory()->create());
        $ram = Employee::factory()->create(['device_user_id' => '7', 'status' => 'active']);

        $csv = "\xEF\xBB\xBFEmployee ID,Name,Department,Time,Attendance Status\n7,Ram,Office,2026-10-12 09:31:02,Check-in\n7,Ram,Office,2026-10-12 17:45:10,Check-out\n31,Unknown,Office,2026-10-12 10:00:00,Check-in\n";

        Livewire::test('pages::settings.attendance-device')
            ->set('file', UploadedFile::fake()->createWithContent('AttendanceRecord.csv', $csv))
            ->call('import')
            ->assertHasNoErrors()
            ->assertSet('imported.marked', 1)
            ->assertSee('31');

        $this->assertSame('09:31', $ram->attendances()->sole()->check_in);
    }

    public function test_xlsx_export_with_separate_date_and_time_columns_can_be_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Person ID</t></si><si><t>Date</t></si><si><t>Time</t></si><si><t>0005</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>46309</v></c><c r="C2"><v>0.375</v></c></row>' // 2026-10-14, 09:00
            .'</sheetData></worksheet>');
        $zip->close();

        $punches = AttendancePunches::fromFile($path, 'xlsx');

        $this->assertCount(1, $punches);
        $this->assertSame('0005', $punches[0]['user']);
        $this->assertSame('2026-10-14 09:00', $punches[0]['time']->format('Y-m-d H:i'));
    }

    public function test_office_pc_helper_can_push_punches_with_the_secret_key(): void
    {
        $emp = Employee::factory()->create(['device_user_id' => '1']);
        $punch = ['computer' => 'OFFICE-PC', 'punches' => [['user' => '1', 'time' => '2026-10-14 09:06:41'], ['user' => '10', 'time' => '2026-10-14 09:30:00']]];

        $this->postJson(route('api.attendance.punches'), $punch)->assertUnauthorized(); // no key set up yet

        Setting::put(['device_push_token' => 'right-key']);
        $this->postJson(route('api.attendance.punches'), $punch, ['Authorization' => 'Bearer wrong-key'])->assertUnauthorized();
        $this->postJson(route('api.attendance.punches'), ['punches' => [['user' => '1', 'time' => 'yesterday']]], ['Authorization' => 'Bearer right-key'])->assertUnprocessable();

        $this->postJson(route('api.attendance.punches'), $punch, ['Authorization' => 'Bearer right-key'])
            ->assertOk()
            ->assertJson(['marked' => 1, 'punches' => 2, 'unknown' => ['10']]);

        $this->assertSame('09:06', $emp->attendances()->sole()->check_in);
        $this->assertSame('OFFICE-PC', Setting::get('device_helper_computer'));
        $this->postJson(route('api.attendance.punches'), ['punches' => []], ['Authorization' => 'Bearer right-key'])->assertOk();
    }

    public function test_helper_download_is_set_up_for_this_site(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('settings.attendance-device.helper'))->assertNotFound(); // no key yet

        Livewire::test('pages::settings.attendance-device')->call('createKey')->assertSee('Download helper');
        $key = Setting::get('device_push_token');
        $this->assertSame(40, strlen($key));

        $response = $this->get(route('settings.attendance-device.helper'))->assertOk();
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $config = json_decode($zip->getFromName('config.json'), true);

        $this->assertSame($key, $config['token']);
        $this->assertSame(rtrim(url('/'), '/'), $config['site']);
        $this->assertStringContainsString("\r\n", $zip->getFromName('Install.bat'));
        $this->assertNotFalse($zip->getFromName('sync.ps1'));
        $this->assertNotFalse($zip->getFromName('setup.ps1'));
        $zip->close();
    }

    public function test_marking_a_synced_day_by_hand_protects_it_and_calendar_shows_check_in(): void
    {
        $this->actingAs(User::factory()->create());
        $emp = Employee::factory()->create(['device_user_id' => '3', 'status' => 'active']);
        $day = today()->isHoliday() ? today()->subDay() : today();
        AttendancePunches::apply([['user' => '3', 'time' => $day->copy()->setTime(9, 7)]]);

        $page = Livewire::test('pages::employee-profile', ['employee' => $emp])->assertSee('9:07 AM')->assertDontSee('09:07');

        $page->call('markDay', $day->toDateString(), 'half_day');
        $this->assertSame('manual', Attendance::sole()->source);

        AttendancePunches::apply([['user' => '3', 'time' => $day->copy()->setTime(8, 0)]]);
        $this->assertSame('half_day', Attendance::sole()->status);
    }
}
