import { Component, Input, signal } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';

/** Pure view component; do not derive authorization or ticket stages in Angular. */
export interface TicketStage {
  id: string;
  label: string;
  status: 'completed' | 'active' | 'pending' | 'blocked' | 'cancelled';
  department: string;
  owner?: string;
  startedAt?: string;
  completedAt?: string;
  sla?: string;
  parallelChildren?: TicketStage[];
}

@Component({
  selector: 'jsc-progress-tracker',
  standalone: true,
  imports: [CommonModule, DatePipe],
  template: `
    <nav aria-label="Ticket workflow progress" class="progress-track">
      @for (stage of stages; track stage.id) {
        <button type="button" class="progress-step" [class.active]="stage.status === 'active'"
          [class.done]="stage.status === 'completed'" [class.blocked]="stage.status === 'blocked'"
          [attr.aria-current]="stage.status === 'active' ? 'step' : null"
          [attr.aria-expanded]="selected() === stage.id"
          (click)="toggle(stage.id)" (focus)="selected.set(stage.id)"
          (keydown.escape)="selected.set(null)">
          <span class="dot" aria-hidden="true"></span><span>{{ stage.label }}</span>
          @if (selected() === stage.id) {
            <span class="stage-detail" role="status">
              <strong>{{ stage.label }}</strong>
              <small>{{ stage.department }} • {{ stage.owner || 'Awaiting claim' }}</small>
              <small>{{ stage.sla || 'No SLA specified' }}</small>
              @if (stage.startedAt) { <small>Started: {{ stage.startedAt | date:'medium' }}</small> }
              @if (stage.completedAt) { <small>Completed: {{ stage.completedAt | date:'medium' }}</small> }
              @if (stage.parallelChildren?.length) {
                <small>Parallel reviews: {{ stage.parallelChildren?.length }}</small>
              }
            </span>
          }
        </button>
      }
    </nav>
  `,
  styles: [`
    .progress-track { display:flex; gap:0; flex-wrap:wrap; margin:1rem 0 3rem; }
    .progress-step { flex:1; min-width:115px; position:relative; border:0; background:transparent;
      padding:10px 5px; color:#52635d; cursor:pointer; outline-offset:3px; }
    .dot { display:block; width:18px; height:18px; border:3px solid #cbd7d0;
      background:#fff; border-radius:50%; margin:0 auto 9px; }
    .progress-step.done .dot { background:#168554; border-color:#168554; }
    .progress-step.active .dot { border-color:#d78a0a; background:#fff0c8; }
    .progress-step.blocked .dot { border-color:#be3c3c; }
    .stage-detail { position:absolute; z-index:10; top:100%; left:0; min-width:225px;
      display:flex; flex-direction:column; text-align:left; gap:5px; background:white;
      box-shadow:0 6px 18px #0002; padding:12px; border:1px solid #dce5df; border-radius:8px; }
    .stage-detail small { font-size:12px; }
    @media(max-width:650px){ .progress-step {flex:0 0 33.33%;} }
  `],
})
export class ProgressTrackerComponent {
  @Input({ required:true }) stages: TicketStage[] = [];
  selected = signal<string|null>(null);
  toggle(id: string) { this.selected.update(previous => previous === id ? null : id); }
}
