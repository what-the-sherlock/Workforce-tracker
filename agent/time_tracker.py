#!/usr/bin/env python3
"""
Workforce Time-Tracking agent (Python)
- Records login_time when started
- Tracks activity (real keyboard/mouse where possible, otherwise simulated)
- Logs idle_start/idle_end when inactivity > threshold
- Records logout_time on exit and updates total_idle_time
- Uses SQLAlchemy ORM and supports SQLite (default) or MySQL (change connection string)
"""

import argparse
import threading
import time
import random
import signal
import sys
from datetime import datetime
from contextlib import contextmanager

from sqlalchemy import create_engine, Column, Integer, String, DateTime, ForeignKey, BigInteger
from sqlalchemy.orm import sessionmaker, declarative_base, relationship

Base = declarative_base()

class Session(Base):
    __tablename__ = "sessions"
    session_id = Column(Integer, primary_key=True, autoincrement=True)
    user_id = Column(String(100), nullable=False)
    machine_id = Column(String(100), nullable=False)
    login_time = Column(DateTime, nullable=False)
    logout_time = Column(DateTime, nullable=True)
    total_idle_seconds = Column(BigInteger, default=0, nullable=False)

    idle_events = relationship("IdleEvent", back_populates="session", cascade="all, delete-orphan")


class IdleEvent(Base):
    __tablename__ = "idle_events"
    event_id = Column(Integer, primary_key=True, autoincrement=True)
    session_id = Column(Integer, ForeignKey("sessions.session_id"), nullable=False)
    idle_start = Column(DateTime, nullable=False)
    idle_end = Column(DateTime, nullable=True)
    duration_seconds = Column(BigInteger, default=0, nullable=False)

    session = relationship("Session", back_populates="idle_events")


DEFAULT_IDLE_THRESHOLD_SECONDS = 120  # 2 minutes

class Agent:
    def __init__(self, db_url, user_id, machine_id, idle_threshold=DEFAULT_IDLE_THRESHOLD_SECONDS, simulate=True):
        self.engine = create_engine(db_url, future=True)
        Base.metadata.create_all(self.engine)
        self.SessionLocal = sessionmaker(bind=self.engine, future=True)
        self.user_id = user_id
        self.machine_id = machine_id
        self.idle_threshold = idle_threshold
        self.simulate = simulate

        self.running = False
        self.current_db_session = None  
        self.current_session_row = None 
        self.current_session_id = None
        self.last_activity = datetime.utcnow()
        self.idle_mode = False
        self.current_idle_event = None
        self.current_idle_event_id = None
        self.lock = threading.Lock()

    @contextmanager
    def db_session(self):
        sess = self.SessionLocal()
        try:
            yield sess
            sess.commit()
        except Exception:
            sess.rollback()
            raise
        finally:
            sess.close()

    def start(self):
        self.running = True
        print(f"[{datetime.utcnow().isoformat()}] Agent starting. simulate={self.simulate}")
        with self.db_session() as db:
            s = Session(
                user_id=self.user_id,
                machine_id=self.machine_id,
                login_time=datetime.utcnow(),
                total_idle_seconds=0
            )
            db.add(s)
            db.flush() 
            self.current_session_row = s
            self.current_session_id = s.session_id
            print(f"[{datetime.utcnow().isoformat()}] Created session id {self.current_session_id}")

        # start threads
        if self.simulate:
            self.activity_thread = threading.Thread(target=self._simulate_activity_loop, daemon=True)
        else:
            self.activity_thread = threading.Thread(target=self._real_activity_loop, daemon=True)
        self.activity_thread.start()

        self.idle_thread = threading.Thread(target=self._idle_detection_loop, daemon=True)
        self.idle_thread.start()

    def stop(self):
        print(f"[{datetime.utcnow().isoformat()}] Agent stopping...")
        self.running = False

        time.sleep(0.5)

        with self.lock:
            if self.idle_mode and self.current_idle_event:
                self._end_idle_event(finalizing=True)

        with self.db_session() as db:
            s = db.get(Session, self.current_session_id)
            if not s:
                print("Warning: session not found to finalize.")
            else:

                total_idle_seconds = 0
                for ie in s.idle_events:
                    total_idle_seconds += ie.duration_seconds or 0
                s.total_idle_seconds = total_idle_seconds
                s.logout_time = datetime.utcnow()
                db.add(s)
                print(f"[{datetime.utcnow().isoformat()}] Session {s.session_id} updated: logout_time set, total_idle_seconds={total_idle_seconds}")

    def _simulate_activity_loop(self):
        """
        Simulate user activity:
        - random bursts of activity where last_activity is updated frequently
        - random longer sleeps to trigger idle
        """
        print("Simulation mode: activity will be simulated.")
        while self.running:
            p_active = random.random()
            if p_active < 0.7:
                burst_len = random.uniform(5, 30)
                end = time.time() + burst_len
                while time.time() < end and self.running:
                    with self.lock:
                        self.last_activity = datetime.utcnow()

                    time.sleep(random.uniform(0.5, 2.0))
            else:
                sleep_time = random.uniform(self.idle_threshold * 0.5, self.idle_threshold * 3)
                time.sleep(sleep_time)

    def _real_activity_loop(self):
        """
        Attempt to use keyboard+mouse (recommended on Windows) then fallback to pynput.
        If neither available, fall back to simulation.
        """

        try:
            import keyboard as kb
            import mouse as ms

            def on_event_km(event):
                with self.lock:
                    self.last_activity = datetime.utcnow()
                print(f"[{self.last_activity.isoformat()}] Activity detected (keyboard/mouse hook)")


            print("Real tracking using 'keyboard' and 'mouse' libraries.")
            kb.hook(on_event_km)
            ms.hook(on_event_km)
            while self.running:
                time.sleep(0.5)


            try:
                kb.unhook_all()
                ms.unhook_all()
            except Exception:
                pass

            return
        except Exception as e_km:
            print("keyboard/mouse libs not available or failed:", e_km)

        try:
            from pynput import mouse as p_mouse, keyboard as p_keyboard

            def on_input_pynput(_):
                with self.lock:
                    self.last_activity = datetime.utcnow()
                print(f"[{self.last_activity.isoformat()}] Activity detected (pynput)")

            def on_move(x, y):
                on_input_pynput(None)

            def on_click(x, y, button, pressed):
                on_input_pynput(None)

            def on_scroll(x, y, dx, dy):
                on_input_pynput(None)

            def on_press(key):
                on_input_pynput(None)

            def on_release(key):
                on_input_pynput(None)

            print("Real tracking using 'pynput' library.")
            with p_mouse.Listener(on_move=on_move, on_click=on_click, on_scroll=on_scroll) as ml, \
                 p_keyboard.Listener(on_press=on_press, on_release=on_release) as kl:
                while self.running:
                    time.sleep(0.5)
            return
        except Exception as e_pyn:
            print("pynput not available or failed:", e_pyn)


        print("Real input hooks unavailable — falling back to simulation.")
        self._simulate_activity_loop()


    def _idle_detection_loop(self):
        """
        Periodically checks last_activity and records idle start/end events.
        """
        check_interval = 2  
        while self.running:
            now = datetime.utcnow()
            with self.lock:
                delta = (now - self.last_activity).total_seconds()
                if not self.idle_mode and delta >= self.idle_threshold:

                    self._start_idle_event(now)
                elif self.idle_mode and delta < self.idle_threshold:

                    self._end_idle_event(now)
            time.sleep(check_interval)

    def _start_idle_event(self, start_time):

        with self.db_session() as db:
            ie = IdleEvent(
                session_id=self.current_session_id,
                idle_start=start_time,
                idle_end=None,
                duration_seconds=0
            )
            db.add(ie)
            db.flush()
            self.current_idle_event_id = ie.event_id
            print(f"[{start_time.isoformat()}] Idle started (event_id={self.current_idle_event_id})")

            self.idle_mode = True
            self.current_idle_event = ie

    def _end_idle_event(self, end_time=None, finalizing=False):

        end_time = end_time or datetime.utcnow()
        try:
            with self.db_session() as db:
                ie = db.get(IdleEvent, self.current_idle_event_id)
                if not ie:
                    print("Warning: idle event not found to end.")
                else:
                    ie.idle_end = end_time
                    duration = int((ie.idle_end - ie.idle_start).total_seconds())
                    if duration < 0:
                        duration = 0
                    ie.duration_seconds = duration
                    db.add(ie)


                    s = db.get(Session, self.current_session_id)
                    if s:
                        s.total_idle_seconds = (s.total_idle_seconds or 0) + ie.duration_seconds
                        db.add(s)

                    print(f"[{end_time.isoformat()}] Idle ended (event_id={ie.event_id}) duration={ie.duration_seconds}s finalizing={finalizing}")

        finally:
            self.idle_mode = False
            self.current_idle_event = None
            self.current_idle_event_id = None


def parse_args():
    parser = argparse.ArgumentParser(description="Workforce Time Tracker Agent")
    parser.add_argument("--db", help="SQLAlchemy DB URL (default sqlite:///time_tracker.db)", default="sqlite:///time_tracker.db")
    parser.add_argument("--user", required=True, help="User id (e.g., 'alice')")
    parser.add_argument("--machine", required=True, help="Machine id (e.g., 'WC-01')")
    parser.add_argument("--threshold", type=int, default=DEFAULT_IDLE_THRESHOLD_SECONDS, help="Idle threshold in seconds")
    parser.add_argument("--simulate", action="store_true", help="Simulate activity instead of using real keyboard/mouse")
    return parser.parse_args()

def main():
    args = parse_args()
    agent = Agent(
        db_url=args.db,
        user_id=args.user,
        machine_id=args.machine,
        idle_threshold=args.threshold,
        simulate=args.simulate
    )

    def handle_exit(signum, frame):
        print(f"Signal {signum} received; shutting down agent.")
        agent.stop()
        sys.exit(0)

    signal.signal(signal.SIGINT, handle_exit)
    try:
        signal.signal(signal.SIGTERM, handle_exit)
    except AttributeError:
        pass  

    try:
        agent.start()
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        handle_exit("KeyboardInterrupt", None)

if __name__ == "__main__":
    main()
