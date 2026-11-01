import 'package:flutter_test/flutter_test.dart';

import 'package:waleed_tech_companion/ai/ai_troubleshooter.dart';

void main() {
  const jobId = '11111111-1111-4111-8111-111111111111';
  const runbookId = '22222222-2222-4222-8222-222222222222';
  const result = '/api/v1/mobile/solutions/$runbookId';

  test('only a fully completed diagnosis exposes a reviewed solution', () {
    for (final status in [
      'queued',
      'running',
      'failed',
      'blocked',
      'cancelled',
    ]) {
      final job = MobileAiJob(
        id: jobId,
        status: status,
        progressPercent: 100,
        resultUrl: result,
        retryAfterSeconds: null,
      );
      expect(job.reviewedRunbookId(), isNull,
          reason: '$status must not expose a solution link');
    }

    const incomplete = MobileAiJob(
      id: jobId,
      status: 'completed',
      progressPercent: 50,
      resultUrl: result,
      retryAfterSeconds: null,
    );
    expect(incomplete.reviewedRunbookId(), isNull);

    const reviewed = MobileAiJob(
      id: jobId,
      status: 'completed',
      progressPercent: 100,
      resultUrl: result,
      retryAfterSeconds: null,
    );
    expect(reviewed.reviewedRunbookId(), runbookId);
  });

  test('the parser cannot turn a pending job into an approved solution', () {
    final pending = MobileAiJob.parse(
      '{"data":{"id":"$jobId","status":"running",'
      '"progress_percent":80,"result_url":"$result",'
      '"retry_after_seconds":30}}',
    );
    expect(pending.reviewedRunbookId(), isNull);
  });
}
