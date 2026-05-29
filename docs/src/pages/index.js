/**
 * Decidesk landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the OpenRegister
 * landing page at openregister.conduction.nl (docs/src/pages/index.js).
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

/* Gavel glyph — same path as decidesk/img/app.svg (Material Design
   Icons "gavel"). Read as the chair's gavel: the moment a decision
   becomes the decision. */
const DECIDESK_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M2.3,20.28L11.9,10.68L10.5,9.26L9.78,9.97C9.39,10.36 8.76,10.36 8.37,9.97L7.66,9.26C7.27,8.87 7.27,8.24 7.66,7.85L13.32,2.19C13.71,1.8 14.34,1.8 14.73,2.19L15.44,2.9C15.83,3.29 15.83,3.92 15.44,4.31L14.73,5L16.15,6.43C16.54,6.04 17.17,6.04 17.56,6.43C17.95,6.82 17.95,7.46 17.56,7.85L18.97,9.26L19.68,8.55C20.07,8.16 20.71,8.16 21.1,8.55L21.8,9.26C22.19,9.65 22.19,10.29 21.8,10.68L16.15,16.33C15.76,16.72 15.12,16.72 14.73,16.33L14.03,15.63C13.63,15.24 13.63,14.6 14.03,14.21L14.73,13.5L13.32,12.09L3.71,21.7C3.32,22.09 2.69,22.09 2.3,21.7C1.91,21.31 1.91,20.67 2.3,20.28M20,19A2,2 0 0,1 22,21V22H12V21A2,2 0 0,1 14,19H20Z" />
  </svg>
);

const TAGLINE = (
  <>
    Decidesk runs the whole decision: meetings, agendas, motions,
    amendments, voting, minutes, and the decision log — with
    configurable workflows for governance bodies, associations,
    corporate boards, and operational meetings.
  </>
);

function UpcomingMeetingsPanel() {
  const rows = [
    { tone: 'var(--c-cobalt-300)', when: 'Mon 14:00' },
    { tone: 'var(--c-lavender-300)', when: 'Wed 10:30' },
    { tone: 'var(--c-mint-500)', when: 'Thu 16:00' },
    { tone: 'var(--c-forest-300)', when: 'Mon 09:00' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.when}
          </div>
        </div>
      ))}
    </div>
  );
}

function MotionsPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', label: 'ADOPTED', w: '80%' },
    { tone: 'var(--c-cobalt-300)', label: 'VOTING', w: '65%' },
    { tone: 'var(--c-lavender-300)', label: 'AMENDED', w: '55%' },
    { tone: 'var(--c-orange-knvb)', label: 'TABLED', w: '40%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              fontWeight: 700,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-700)',
              minWidth: 50,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-50)',
              borderRadius: 1,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.w,
                background: 'var(--c-cobalt-300)',
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function ActionItemsPanel() {
  const rows = [
    { tone: 'var(--c-orange-knvb)', due: 'overdue' },
    { tone: 'var(--c-mint-500)', due: 'today' },
    { tone: 'var(--c-lavender-300)', due: 'Fri' },
    { tone: 'var(--c-cobalt-300)', due: 'next wk' },
    { tone: 'var(--c-forest-300)', due: 'next wk' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 18,
              height: 18,
              borderRadius: 2,
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.due}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Upcoming meetings',
    desc: 'Your next meetings across every body you sit on — agendas, papers, and the join link, all on the dashboard you already open.',
    panel: <UpcomingMeetingsPanel />,
  },
  {
    title: 'Motions in play',
    desc: 'Motions and amendments by status: tabled, in debate, voting, adopted. Chair controls, configurable quorum, and a clear trail from proposal to decision.',
    panel: <MotionsPanel />,
  },
  {
    title: 'Open action items',
    desc: 'Every action item assigned out of a meeting, sorted by due date. Follow each one through to completion, with the decision log as the source of truth.',
    panel: <ActionItemsPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Decidesk, meetings, motions, and decision logs for Nextcloud"
      description="Meetings, motions, voting, and minutes on Nextcloud. Configurable workflows for boards, councils, and operational decision logs."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="decidesk"
          /* status + version dropped — preset 2.10+ auto-derives from appinfo/info.xml */
          locales="EN"
          title="Decidesk"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/decidesk',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://codeberg.org/Conduction/decidesk',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={DECIDESK_ICON}
          illustration={<AppMock app="decidesk" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="The meeting follows you to the dashboard."
          lede="Install Decidesk and the home screen surfaces your next meetings, the motions in play, and the action items still open against you."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
