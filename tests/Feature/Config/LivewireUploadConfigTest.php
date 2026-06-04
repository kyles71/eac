<?php

declare(strict_types=1);

it('allows temporary uploads up to the video upload limit', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toContain('max:'.config('app.file_uploads.video_max_size_kilobytes'));
});
