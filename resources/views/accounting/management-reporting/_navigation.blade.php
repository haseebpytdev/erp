<nav class="mr-nav" aria-label="Accounting reports">
  <a class="{{ $filters['mode']==='management' ? 'active' : '' }}" href="{{ route('accounting.management-reports.management', ['as_of'=>$filters['as_of'],'branch_id'=>$filters['branch_id']]) }}">Management Overview</a>
  <a class="{{ $filters['mode']==='profit-and-loss' ? 'active' : '' }}" href="{{ route('accounting.management-reports.profit-and-loss', ['from'=>$filters['from'],'to'=>$filters['to'],'branch_id'=>$filters['branch_id']]) }}">Profit &amp; Loss</a>
  <a href="{{ route('accounting.management-reports.trial-balance', ['from'=>$filters['from'],'to'=>$filters['to'],'branch_id'=>$filters['branch_id']]) }}">Trial Balance</a>
  <a class="{{ $filters['mode']==='balance-sheet' ? 'active' : '' }}" href="{{ route('accounting.management-reports.balance-sheet', ['as_of'=>$filters['as_of'],'branch_id'=>$filters['branch_id']]) }}">Balance Sheet</a>
  @if($reportCenterUrl)<a href="{{ $reportCenterUrl }}">Ledger Reports / Print Center</a>@endif
</nav>
